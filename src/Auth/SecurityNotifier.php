<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Notification\NotificationChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Repository\UserRepositoryInterface;

/**
 * High-trust account events delivered out-of-band (HC-106).
 *
 * When any of these happen, the account owner gets an email so a
 * compromised session can't silently degrade security:
 *
 *   - {@see EVENT_TOTP_DISABLED}
 *   - {@see EVENT_PASSWORD_CHANGED}
 *   - {@see EVENT_APIKEY_GENERATED}
 *   - {@see EVENT_APIKEY_REVOKED}
 *   - {@see EVENT_LOGIN_LOCKOUT}   (failed-attempts lockout just tripped)
 *   - {@see EVENT_LOGIN_NEW_IP}    (login from an IP we haven't seen)
 *
 * Design:
 *   - Fire-and-forget: every exception is swallowed into error_log;
 *     a flaky SMTP must NOT block the underlying action (the audit
 *     row in `hc_audit_log` is the authoritative record).
 *   - Gated by `hc_config.security_email_enabled` (default 'Y').
 *     Operators mute during known-noisy migrations.
 *   - NOT gated on `hc_user.email_notifications` — that toggle is
 *     for reminder email; security trumps it because the recipient
 *     needs to know about these events even if they muted pings.
 *   - Caller is responsible for deciding WHEN — this service just
 *     builds and dispatches the message when asked.
 */
final class SecurityNotifier
{
    public const EVENT_TOTP_DISABLED     = 'totp_disabled';
    public const EVENT_PASSWORD_CHANGED  = 'password_changed';
    public const EVENT_APIKEY_GENERATED  = 'apikey_generated';
    public const EVENT_APIKEY_REVOKED    = 'apikey_revoked';
    public const EVENT_LOGIN_LOCKOUT     = 'login_lockout';
    public const EVENT_LOGIN_NEW_IP      = 'login_new_ip';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly UserRepositoryInterface $users,
        private readonly NotificationChannel $emailChannel,
        private readonly string $baseUrl = '',
    ) {}

    /**
     * Dispatch an email for $event. Every exception is caught.
     *
     * @param array<string,string|int> $details event-specific fields
     *        (e.g. ['ip' => '203.0.113.7'] for login events).
     */
    public function notify(string $login, string $event, array $details = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = $this->users->findByLogin($login);
        if ($user === null || $user['email'] === null || $user['email'] === '') {
            return;
        }

        $template = $this->buildTemplate($event, $login, $details);
        if ($template === null) {
            return;
        }

        try {
            $this->emailChannel->send(new NotificationMessage(
                title: $template['subject'],
                body: $template['body'],
                priority: NotificationMessage::PRIORITY_HIGH,
                tags: ['security'],
                recipient: $user['email'],
            ));
        } catch (\Throwable $e) {
            error_log("SecurityNotifier: event={$event} login={$login}: "
                . $e->getMessage());
        }
    }

    private function isEnabled(): bool
    {
        $rows = $this->db->query(
            "SELECT value FROM hc_config WHERE setting = 'security_email_enabled'",
        );
        if ($rows === []) {
            // Default ON — spec: "Operator can mute" means the setting
            // must be set to 'N' to suppress; missing row = on.
            return true;
        }
        $val = (string) ($rows[0]['value'] ?? '');

        // Only 'N' suppresses; anything else (empty, stray value, 'Y')
        // leaves the feature enabled. Fail-open on security-email
        // is the correct direction — better one extra email than a
        // silently-compromised account.
        return $val !== 'N';
    }

    /**
     * @param array<string,string|int> $details
     *
     * @return array{subject:string,body:string}|null
     */
    private function buildTemplate(string $event, string $login, array $details): ?array
    {
        $when = date('Y-m-d H:i:s T');
        $settingsLine = $this->baseUrl === ''
            ? 'Visit the settings page on your HomeCare install to review.'
            : 'Review: ' . rtrim($this->baseUrl, '/') . '/settings.php';

        $footer = "\n\nIf this wasn't you, change your password immediately.\n"
            . $settingsLine . "\n";

        switch ($event) {
            case self::EVENT_TOTP_DISABLED:
                return [
                    'subject' => 'Two-factor authentication disabled on your HomeCare account',
                    'body' => "Hi {$login},\n\n"
                        . 'Two-factor authentication was just turned off on your '
                        . "HomeCare account at {$when}."
                        . $footer,
                ];

            case self::EVENT_PASSWORD_CHANGED:
                return [
                    'subject' => 'HomeCare password changed',
                    'body' => "Hi {$login},\n\n"
                        . "Your HomeCare password was just changed at {$when}."
                        . $footer,
                ];

            case self::EVENT_APIKEY_GENERATED:
                return [
                    'subject' => 'HomeCare API key generated',
                    'body' => "Hi {$login},\n\n"
                        . 'A new API key was just generated on your HomeCare '
                        . "account at {$when}."
                        . $footer,
                ];

            case self::EVENT_APIKEY_REVOKED:
                return [
                    'subject' => 'HomeCare API key revoked',
                    'body' => "Hi {$login},\n\n"
                        . "Your HomeCare API key was just revoked at {$when}. "
                        . 'Any scripts using it are now returning 401.'
                        . $footer,
                ];

            case self::EVENT_LOGIN_LOCKOUT:
                return [
                    'subject' => 'HomeCare account locked after repeated failed logins',
                    'body' => "Hi {$login},\n\n"
                        . "Your HomeCare account was just locked at {$when} "
                        . 'after too many failed login attempts. It will unlock '
                        . "automatically in 15 minutes.\n\n"
                        . 'If this was you mistyping a password, no action is '
                        . "needed. If it wasn't, someone may be trying to break "
                        . "in — change your password when you're next logged in."
                        . $footer,
                ];

            case self::EVENT_LOGIN_NEW_IP:
                $ip = (string) ($details['ip'] ?? 'unknown');
                $prevIp = (string) ($details['previous_ip'] ?? 'none on record');
                return [
                    'subject' => 'HomeCare sign-in from a new IP address',
                    'body' => "Hi {$login},\n\n"
                        . "Your HomeCare account just signed in from {$ip} "
                        . "at {$when}.\n\n"
                        . "Previous sign-in IP: {$prevIp}."
                        . $footer,
                ];
        }

        return null;
    }
}
