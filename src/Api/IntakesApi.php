<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Auth\Authorization;
use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;
use HomeCare\Service\PrnLimitService;
use InvalidArgumentException;

/**
 * GET  /api/v1/intakes.php?schedule_id=N&days=30  -- history (HC-031)
 * POST /api/v1/intakes.php                         -- record (HC-032)
 *
 * The read path ({@see handle}) returns the last N days of intakes for
 * a schedule. The write path ({@see record}) creates a new intake row
 * from a JSON body; requires `caregiver` role or higher.
 *
 * HC-238: when a {@see PrnLimitService} is injected, the write path
 * also reports PRN dose limits. Over-limit doses are still recorded
 * (caregivers may have orders for an extra dose); the response gains
 * a `warning` (string|null) and, for limited PRN schedules, a
 * `prn_limit` block evaluated after the new dose. Existing fields are
 * unchanged.
 */
final class IntakesApi
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    /** @var callable():int */
    private readonly mixed $clock;

    public function __construct(
        private readonly ScheduleRepositoryInterface $schedules,
        private readonly IntakeRepositoryInterface $intakes,
        ?callable $clock = null,
        private readonly ?PrnLimitService $prnLimits = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param array<string,mixed> $query
     */
    public function handle(array $query): ApiResponse
    {
        $scheduleId = self::intParam($query, 'schedule_id');
        if ($scheduleId === null) {
            return ApiResponse::error('schedule_id is required (positive integer)', 400);
        }

        if ($this->schedules->getScheduleById($scheduleId) === null) {
            return ApiResponse::error('schedule not found', 404);
        }

        $days = self::intParam($query, 'days') ?? self::DEFAULT_DAYS;
        if ($days > self::MAX_DAYS) {
            $days = self::MAX_DAYS;
        }

        /** @var callable():int $clock */
        $clock = $this->clock;
        $sinceTs = ($clock)() - ($days * 86400);
        $since = date('Y-m-d H:i:s', $sinceTs);

        $rows = $this->intakes->getIntakesSince($scheduleId, $since);

        return ApiResponse::ok([
            'schedule_id' => $scheduleId,
            'days' => $days,
            'since' => $since,
            'intakes' => $rows,
        ]);
    }

    /**
     * Record a new intake.
     *
     * @param array<string,mixed> $body Decoded JSON body.
     * @param string              $role Role of the authenticated user (see
     *                                  {@see Authorization}).
     */
    public function record(array $body, string $role): ApiResponse
    {
        try {
            $auth = new Authorization($role);
        } catch (InvalidArgumentException) {
            return ApiResponse::error('unknown role', 403);
        }
        if (!$auth->canWrite()) {
            return ApiResponse::error('caregiver role required', 403);
        }

        $scheduleId = self::intBody($body, 'schedule_id');
        if ($scheduleId === null) {
            return ApiResponse::error('schedule_id is required (positive integer)', 400);
        }

        if ($this->schedules->getScheduleById($scheduleId) === null) {
            return ApiResponse::error('schedule not found', 404);
        }

        $takenTime = self::stringBody($body, 'taken_time');
        $note = self::stringBody($body, 'note');

        if ($takenTime !== null && !self::validDateTime($takenTime)) {
            return ApiResponse::error('taken_time must be YYYY-MM-DD HH:MM:SS', 400);
        }

        // HC-238: check limits BEFORE the insert so the new dose isn't
        // counted against itself. An omitted taken_time means "now".
        $doseAt = null;
        $before = null;
        if ($this->prnLimits !== null) {
            /** @var callable():int $clock */
            $clock = $this->clock;
            $doseAt = $takenTime ?? date('Y-m-d H:i:s', ($clock)());
            $before = $this->prnLimits->statusFor($scheduleId, $doseAt);
        }

        $newId = $this->intakes->recordIntake($scheduleId, $takenTime, $note);

        $data = [
            'id' => $newId,
            'schedule_id' => $scheduleId,
            'taken_time' => $takenTime,
            'note' => $note,
        ];
        if ($this->prnLimits !== null) {
            $data['warning'] = $before?->warningMessage();
            $after = $before === null ? null : $this->prnLimits->statusFor($scheduleId, $doseAt);
            if ($after !== null) {
                $data['prn_limit'] = $after->toArray();
            }
        }

        return ApiResponse::ok($data, 201);
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function intParam(array $query, string $key): ?int
    {
        if (!isset($query[$key]) || !is_scalar($query[$key])) {
            return null;
        }
        $raw = (string) $query[$key];
        if (!preg_match('/^\d+$/', $raw)) {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function intBody(array $body, string $key): ?int
    {
        if (!isset($body[$key])) {
            return null;
        }
        $v = $body[$key];
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/^\d+$/', $v)) {
            $n = (int) $v;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function stringBody(array $body, string $key): ?string
    {
        if (!isset($body[$key]) || !is_string($body[$key]) || $body[$key] === '') {
            return null;
        }

        return $body[$key];
    }

    /**
     * Strict MySQL DATETIME literal check: YYYY-MM-DD HH:MM:SS.
     */
    private static function validDateTime(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s) !== 1) {
            return false;
        }
        // A valid shape can still be nonsense (2026-13-40 99:99:99). Round-trip
        // through DateTimeImmutable to confirm it's a real moment in time.
        try {
            $dt = new \DateTimeImmutable($s);
        } catch (\Exception) {
            return false;
        }

        return $dt->format('Y-m-d H:i:s') === $s;
    }
}
