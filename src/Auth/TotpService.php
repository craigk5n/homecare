<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP / recovery-code primitives for HomeCare's 2FA flow.
 *
 * Thin wrapper over {@see Google2FA} plus helpers for recovery codes.
 * Keeps all the cryptography decisions in one place so the login /
 * settings pages can stay ignorant of library details.
 *
 * Verification uses a ±1-step window (a common lenience for clock
 * skew on phones and servers); anything wider starts to weaken
 * replay protection, so we don't expose it as a knob.
 */
final class TotpService
{
    /** How many 30-second steps either side of "now" still verify. */
    public const VERIFY_WINDOW = 1;

    /** Recovery codes default count — OWASP suggests ~8-10. */
    public const DEFAULT_RECOVERY_CODE_COUNT = 10;

    /** @var callable():string */
    private readonly mixed $randomCodeFactory;

    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA(),
        ?callable $randomCodeFactory = null,
    ) {
        $this->randomCodeFactory = $randomCodeFactory
            ?? static fn(): string => self::defaultRecoveryCode();
    }

    /**
     * Return a fresh Base32-encoded TOTP seed. Persist via
     * {@see \HomeCare\Repository\UserRepositoryInterface::setTotpSecret()}.
     */
    public function generateSecret(): string
    {
        $secret = $this->google2fa->generateSecretKey();

        return $secret;
    }

    /**
     * Build the `otpauth://` URI that authenticator apps consume when
     * they scan the QR. `$accountLabel` is typically the user's login
     * and `$issuer` is the app name displayed next to it.
     */
    public function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $accountLabel, $secret);
    }

    /**
     * Verify a 6-digit code against a Base32 secret with ±1 step window.
     *
     * Returns false for any malformed input (wrong length, non-digits,
     * empty secret) rather than throwing -- the login flow treats every
     * "no" identically regardless of cause, to avoid leaking whether
     * the secret or the code was at fault.
     */
    public function verifyCode(#[\SensitiveParameter] string $secret, string $code): bool
    {
        if ($secret === '') {
            return false;
        }
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            return (bool) $this->google2fa->verifyKey($secret, $code, self::VERIFY_WINDOW);
        } catch (\Throwable) {
            // google2fa throws on secrets that aren't valid Base32.
            return false;
        }
    }

    /**
     * Return $n freshly-minted recovery codes. The raw strings are
     * returned to the caller (to show the user once) -- hashes go
     * through {@see self::hashRecoveryCode()} for DB storage.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $n = self::DEFAULT_RECOVERY_CODE_COUNT): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException("recovery code count must be >= 1, got {$n}");
        }

        /** @var callable():string $factory */
        $factory = $this->randomCodeFactory;

        $out = [];
        $seen = [];
        while (count($out) < $n) {
            $code = ($factory)();
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $code;
        }

        return $out;
    }

    /**
     * Canonical hash used for storing AND comparing recovery codes.
     * Normalises case + strips separators so "ab12-cd34" and
     * "AB12CD34" hash to the same value.
     */
    public static function hashRecoveryCode(string $raw): string
    {
        return hash('sha256', self::normalise($raw));
    }

    /**
     * If $code matches one of the hashes in $storedHashes, pop it and
     * return the new list; otherwise return null.
     *
     * @param list<string> $storedHashes
     *
     * @return list<string>|null
     */
    public function consumeRecoveryCode(
        #[\SensitiveParameter]
        string $code,
        array $storedHashes,
    ): ?array {
        $needle = self::hashRecoveryCode($code);
        $remaining = [];
        $found = false;
        foreach ($storedHashes as $hash) {
            if (!$found && hash_equals($hash, $needle)) {
                $found = true;
                continue;
            }
            $remaining[] = $hash;
        }

        return $found ? $remaining : null;
    }

    /**
     * Parse a DB-stored JSON list of recovery-code hashes. Returns an
     * empty list for null / empty / malformed JSON so callers never
     * need to null-check.
     *
     * @return list<string>
     */
    public static function decodeStoredRecoveryCodes(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $h) {
            if (is_string($h) && $h !== '') {
                $out[] = $h;
            }
        }

        return $out;
    }

    private static function defaultRecoveryCode(): string
    {
        // 10-char alphanumeric → ~52 bits of entropy (set size 32^10).
        // Rendered as "ab12-cd34-ef" style on the settings page so it's
        // readable at a glance without changing the stored value.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $len = 10;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function normalise(string $raw): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $raw) ?? '');
    }
}
