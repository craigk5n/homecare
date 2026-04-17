<?php

declare(strict_types=1);

namespace HomeCare\Audit;

use HomeCare\Database\DatabaseInterface;

/**
 * Writes one row per significant action to hc_audit_log.
 *
 * The logger takes callables for the current user and client IP rather
 * than reading `$GLOBALS['login']` / `$_SERVER['REMOTE_ADDR']` directly
 * so it can be unit-tested and reused from a CLI context (where neither
 * global is meaningful). `audit_log()` in `includes/homecare.php`
 * provides a request-scoped singleton wired up to the live globals.
 *
 * Failures never throw -- audit logging must not break the caller's
 * happy path -- but they're reported via `error_log()` so operations
 * still sees breakage.
 */
final class AuditLogger
{
    /** @var callable():?string */
    private readonly mixed $loginProvider;

    /** @var callable():?string */
    private readonly mixed $ipProvider;

    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        ?callable $loginProvider = null,
        ?callable $ipProvider = null,
        ?callable $clock = null,
    ) {
        $this->loginProvider = $loginProvider ?? static fn(): ?string => null;
        $this->ipProvider = $ipProvider ?? static fn(): ?string => null;
        $this->clock = $clock ?? static fn(): string => date('Y-m-d H:i:s');
    }

    /**
     * Record one audit event.
     *
     * @param string                         $action     dotted event name, e.g. "intake.recorded"
     * @param string                         $entityType short table-ish name, e.g. "schedule"
     * @param int|null                       $entityId   primary key of the affected row, if any
     * @param array<string,mixed>            $details    additional context; serialised as JSON
     */
    public function log(
        string $action,
        string $entityType = '',
        ?int $entityId = null,
        array $details = [],
    ): void {
        $json = null;
        if ($details !== []) {
            $encoded = json_encode(
                $details,
                JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            $json = $encoded === false ? null : $encoded;
        }

        try {
            $this->db->execute(
                'INSERT INTO hc_audit_log
                    (user_login, action, entity_type, entity_id, details, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->currentLogin(),
                    $action,
                    $entityType === '' ? null : $entityType,
                    $entityId,
                    $json,
                    $this->currentIp(),
                    $this->currentTimestamp(),
                ],
            );
        } catch (\Throwable $e) {
            // Never fail the request because audit logging broke. Surface
            // the failure in the server log so ops notices.
            error_log('AuditLogger: ' . $e->getMessage());
        }
    }

    private function currentLogin(): ?string
    {
        /** @var callable():?string $fn */
        $fn = $this->loginProvider;
        $value = ($fn)();

        return ($value === null || $value === '') ? null : $value;
    }

    private function currentIp(): ?string
    {
        /** @var callable():?string $fn */
        $fn = $this->ipProvider;
        $value = ($fn)();

        return ($value === null || $value === '') ? null : $value;
    }

    private function currentTimestamp(): string
    {
        /** @var callable():string $fn */
        $fn = $this->clock;

        return ($fn)();
    }
}
