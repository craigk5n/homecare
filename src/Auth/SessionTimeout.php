<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use InvalidArgumentException;

/**
 * Pure "is this session still alive?" evaluator.
 *
 * Takes the last-activity timestamp, the current time, and the configured
 * window -- no side effects, no globals. The wiring in
 * `includes/validate.php` reads `$_SESSION['last_activity']` and
 * `hc_config.session_timeout`, calls {@see evaluate()}, and acts on the
 * returned {@see SessionState}. Keeping the math here means the check is
 * trivially unit-testable and portable across any future entrypoint
 * (cron script, CLI tool, API handler).
 */
final class SessionTimeout
{
    public const DEFAULT_TIMEOUT_MINUTES = 30;

    private readonly int $timeoutSeconds;

    public function __construct(int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES)
    {
        if ($timeoutMinutes <= 0) {
            throw new InvalidArgumentException(
                "Session timeout must be positive; got {$timeoutMinutes}",
            );
        }

        $this->timeoutSeconds = $timeoutMinutes * 60;
    }

    /**
     * @param int|null $lastActivity Unix timestamp of the last authenticated
     *                               hit, or null when the session has none
     *                               recorded yet.
     * @param int      $now          Unix timestamp to compare against.
     */
    public function evaluate(?int $lastActivity, int $now): SessionState
    {
        if ($lastActivity === null) {
            return SessionState::New;
        }

        if ($now - $lastActivity > $this->timeoutSeconds) {
            return SessionState::Expired;
        }

        return SessionState::Active;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
