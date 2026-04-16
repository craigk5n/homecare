<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;

final readonly class CadenceCheck
{
    public function __construct(
        private IntakeRepositoryInterface $intakes,
        private ScheduleRepositoryInterface $schedules,
        private ScheduleCalculator $calc
    ) {}

    /**
     * @param positive-int $scheduleId
     * @param positive-int $sampleSize
     */
    /**
     * @param positive-int $scheduleId
     * @param positive-int $sampleSize
     */
    public function divergence(int $scheduleId, int $sampleSize = 5): ?float
    {
        $schedule = $this->schedules->getScheduleById($scheduleId);
        if (empty($schedule) || empty($schedule['frequency']) || empty($schedule['start_date'])) {
            return null;
        }

        $since = $schedule['start_date'];
        $recentIntakes = $this->intakes->getIntakesSince($scheduleId, $since);

        if (count($recentIntakes) < $sampleSize + 1) {
            return null;
        }

        // Last N+1 recent
        $recent = array_slice($recentIntakes, 0, $sampleSize + 1); // assume recent first, DESC

        // Filter and sort ASC
        $times = [];
        foreach ($recent as $intake) {
            $takenTime = $intake['taken_time'];
            if (!is_string($takenTime)) {
                continue;
            }
            $ts = strtotime($takenTime);
            if ($ts !== false) {
                $times[] = $ts;
            }
        }
        sort($times);

        if (count($times) < $sampleSize + 1) {
            return null;
        }

        $intervals = [];
        for ($i = 1; $i < count($times); $i++) {
            $intervals[] = $times[$i] - $times[$i - 1];
        }

        $observedSeconds = array_sum($intervals) / count($intervals);
        $expectedSeconds = $this->calc->frequencyToSeconds($schedule['frequency']);

        if ($expectedSeconds === 0) {
            return null;
        }

        return ($observedSeconds / 3600) / ($expectedSeconds / 3600);
    }

    public function getWarningText(int $scheduleId, int $sampleSize = 5): ?string
    {
        if ($scheduleId < 1 || $sampleSize < 1) {
            return null;
        }
        $divergence = $this->divergence($scheduleId, $sampleSize);
        if ($divergence === null || !($divergence < 0.5 || $divergence > 2.0)) {
            return null;
        }

        $schedule = $this->schedules->getScheduleById($scheduleId);
        if (!$schedule || empty($schedule['frequency'])) {
            return null;
        }

        $expectedHours = $this->calc->frequencyToSeconds($schedule['frequency']) / 3600;
        $observedHours = $divergence * $expectedHours;

        $observedStr = round($observedHours) . 'h';
        $suggested = round($expectedHours / $observedHours) . 'h';

        return "Recent doses average every {$observedStr}, but the schedule says ~" . round($expectedHours) . "h. Did you mean to set frequency to {$suggested}?";
    }


    public function shouldWarn(?float $divergence): bool
    {
        return $divergence !== null && ($divergence < 0.5 || $divergence > 2.0);
    }

    public function formatWarning(float $observedHours, float $expectedHours): string
    {
        $observed = round($observedHours) . 'h';
        $suggested = round($expectedHours / ($observedHours / $expectedHours)) . 'h';
        return "Recent doses average every {$observed}, but the schedule says {$expectedHours}h. Did you mean to set frequency to ~{$suggested}?";
    }
}
