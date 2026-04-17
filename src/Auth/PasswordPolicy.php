<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DatabaseInterface;

/**
 * Server-side password-strength enforcement.
 *
 * Rules applied by {@see validate()} in this order:
 *   1. Minimum length  (default 10; `hc_config.password_min_length`)
 *   2. Composition     (at least one non-alphanumeric char, OR length ≥ 14)
 *   3. Identity        (no case-insensitive substring match against the
 *                       user's login / email / firstname / lastname)
 *   4. Common list     (rejects anything in the bundled common-password
 *                       list — see `resources/common-passwords.txt`)
 *
 * {@see validate()} returns a list of user-facing violation messages;
 * an empty list means the password is acceptable. Callers (settings /
 * password reset / admin create) render the messages verbatim — they
 * are phrased actionably ("must be at least 10 characters"), not as
 * opaque codes.
 *
 * The common-password list is loaded lazily: the first `validate()`
 * call that reaches rule 4 reads the file and caches a flipped-array
 * for O(1) lookup. 54k entries → ~10 MB memory post-load; accept the
 * cost on the first password change rather than every page view.
 */
final class PasswordPolicy
{
    public const DEFAULT_MIN_LENGTH = 10;
    public const DEFAULT_REQUIRE_SYMBOL = true;
    public const LONG_PASSWORD_BYPASS = 14;

    /** @var array<string,true>|null */
    private ?array $commonPasswords = null;

    private int $minLength;

    private bool $requireSymbol;

    public function __construct(
        ?DatabaseInterface $config = null,
        private readonly ?string $commonPasswordsFile = null,
    ) {
        $this->minLength = self::DEFAULT_MIN_LENGTH;
        $this->requireSymbol = self::DEFAULT_REQUIRE_SYMBOL;

        if ($config !== null) {
            $this->loadConfig($config);
        }
    }

    /**
     * Construct with explicit knobs. Useful in tests and when callers
     * want to override policy without wiring a DB.
     */
    public static function withRules(
        int $minLength = self::DEFAULT_MIN_LENGTH,
        bool $requireSymbol = self::DEFAULT_REQUIRE_SYMBOL,
        ?string $commonPasswordsFile = null,
    ): self {
        $p = new self(null, $commonPasswordsFile);
        $p->minLength = $minLength;
        $p->requireSymbol = $requireSymbol;

        return $p;
    }

    /**
     * @param array{login?:string,email?:string,firstname?:string,lastname?:string} $userContext
     *
     * @return list<string> Human-readable violation messages; empty = pass.
     */
    public function validate(
        #[\SensitiveParameter]
        string $password,
        array $userContext = [],
    ): array {
        $violations = [];

        // 1. Minimum length.
        $len = mb_strlen($password);
        if ($len < $this->minLength) {
            $violations[] = sprintf(
                'Password must be at least %d characters (currently %d).',
                $this->minLength,
                $len,
            );
        }

        // 2. Composition: symbol required unless length ≥ LONG_PASSWORD_BYPASS.
        if ($this->requireSymbol && $len < self::LONG_PASSWORD_BYPASS
            && preg_match('/[^A-Za-z0-9]/', $password) !== 1
        ) {
            $violations[] = sprintf(
                'Password needs at least one non-alphanumeric character, '
                . 'or must be %d or more characters long.',
                self::LONG_PASSWORD_BYPASS,
            );
        }

        // 3. Identity: substring overlap with the user's own identifiers.
        //    We compare case-insensitively and skip fragments shorter than
        //    4 characters (a 2-letter name shouldn't mean every password
        //    containing "jo" gets rejected).
        $needle = mb_strtolower($password);
        foreach (['login', 'email', 'firstname', 'lastname'] as $field) {
            $value = $userContext[$field] ?? null;
            if (!is_string($value) || mb_strlen($value) < 4) {
                continue;
            }
            $fragment = mb_strtolower($field === 'email'
                ? (string) strstr($value, '@', true) ?: $value
                : $value);
            // Short email local-parts (e.g. "a@example.com") fall below
            // the 4-char floor and are skipped, same as short names.
            if (mb_strlen($fragment) >= 4 && str_contains($needle, $fragment)) {
                $violations[] = 'Password must not contain your '
                    . $field . '.';
            }
        }

        // 4. Common-password list.
        if ($this->isCommonPassword($password)) {
            $violations[] = 'This password appears in common-password lists. '
                . 'Please pick something less predictable.';
        }

        return $violations;
    }

    private function isCommonPassword(string $password): bool
    {
        if ($this->commonPasswords === null) {
            $this->commonPasswords = self::loadCommonPasswords(
                $this->commonPasswordsFile ?? self::defaultListPath(),
            );
        }

        return isset($this->commonPasswords[mb_strtolower($password)]);
    }

    /**
     * @return array<string,true>
     */
    private static function loadCommonPasswords(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = (string) file_get_contents($path);
        $list = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $out = [];
        foreach ($list as $line) {
            $trimmed = strtolower(trim($line));
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                $out[$trimmed] = true;
            }
        }

        return $out;
    }

    private static function defaultListPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/common-passwords.txt';
    }

    private function loadConfig(DatabaseInterface $db): void
    {
        $rows = $db->query(
            "SELECT setting, value FROM hc_config
             WHERE setting IN ('password_min_length', 'password_require_symbol')",
        );
        foreach ($rows as $row) {
            $key = (string) ($row['setting'] ?? '');
            $val = (string) ($row['value'] ?? '');
            if ($key === 'password_min_length' && ctype_digit($val) && (int) $val > 0) {
                $this->minLength = (int) $val;
            } elseif ($key === 'password_require_symbol') {
                $this->requireSymbol = $val === 'Y';
            }
        }
    }
}
