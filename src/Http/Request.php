<?php

declare(strict_types=1);

namespace HomeCare\Http;

/**
 * Testable wrapper around the PHP request superglobals.
 *
 * Replaces direct `$_GET` / `$_POST` / `$_SERVER` reads in new code so
 * handlers can be unit-tested without mutating process-wide state. The
 * legacy `getGetValue()` / `getPostValue()` helpers in
 * `includes/formvars.php` remain for the existing pages; new pages and
 * refactored handlers should build a Request via {@see fromGlobals()}.
 *
 * XSS guard: the same banned-tag list from `preventHacking()` is applied
 * here, but instead of killing the request inside the helper we throw
 * {@see InvalidRequestException} so callers can render a clean error.
 * Hex-escape sequences like `\x3c` are decoded before scanning so an
 * attacker cannot bypass the filter by encoding the `<`.
 */
final class Request
{
    /**
     * Same list as `preventHacking()` in `includes/formvars.php`. Kept in
     * sync deliberately: if you add a tag there, mirror it here and vice
     * versa so the HTTP abstraction and the legacy path reject the same
     * inputs.
     *
     * @var list<string>
     */
    private const BANNED_TAGS = [
        'APPLET', 'BODY', 'EMBED', 'FORM', 'HEAD',
        'HTML', 'IFRAME', 'LINK', 'META', 'NOEMBED',
        'NOFRAMES', 'NOSCRIPT', 'OBJECT', 'SCRIPT',
    ];

    /**
     * @param array<string,scalar|array<mixed>|null> $get
     * @param array<string,scalar|array<mixed>|null> $post
     * @param array<string,scalar|null>              $server
     */
    public function __construct(
        private readonly array $get = [],
        private readonly array $post = [],
        private readonly array $server = [],
    ) {}

    public static function fromGlobals(): self
    {
        /** @var array<string,scalar|array<mixed>|null> $get */
        $get = $_GET;
        /** @var array<string,scalar|array<mixed>|null> $post */
        $post = $_POST;
        /** @var array<string,scalar|null> $server */
        $server = $_SERVER;

        return new self($get, $post, $server);
    }

    /**
     * Read a value from the GET query string.
     *
     * @param scalar|null $default
     *
     * @return scalar|array<mixed>|null
     *
     * @throws InvalidRequestException When the value contains a banned HTML tag.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->get)) {
            return $default;
        }

        return $this->guard($key, $this->get[$key]);
    }

    /**
     * Read a value from the POST body.
     *
     * @param scalar|null $default
     *
     * @return scalar|array<mixed>|null
     *
     * @throws InvalidRequestException When the value contains a banned HTML tag.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->post)) {
            return $default;
        }

        return $this->guard($key, $this->post[$key]);
    }

    /**
     * Parse a POST-or-GET value as an integer. Returns null when the key is
     * missing or the value is not a decimal integer (optionally signed).
     *
     * Matches the semantics of `getIntValue()` in `includes/formvars.php`
     * but without the fatal-on-mismatch mode; callers that need
     * "required int or die" can assert on the returned value themselves.
     */
    public function getInt(string $key): ?int
    {
        $raw = $this->post[$key] ?? $this->get[$key] ?? null;
        if (!is_scalar($raw)) {
            return null;
        }

        $str = (string) $raw;
        if ($str === '' || preg_match('/^-?[0-9]+$/', $str) !== 1) {
            return null;
        }

        return (int) $str;
    }

    public function method(): string
    {
        $m = $this->server['REQUEST_METHOD'] ?? 'GET';

        return is_string($m) ? strtoupper($m) : 'GET';
    }

    /**
     * @param scalar|array<mixed>|null $value
     *
     * @return scalar|array<mixed>|null
     *
     * @throws InvalidRequestException
     */
    private function guard(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            array_walk_recursive($value, function (mixed $v) use ($key): void {
                // Skip nested arrays/null/objects; array_walk_recursive only
                // reaches leaves, and the input type allows scalar leaves.
                if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
                    self::assertSafe($key, (string) $v);
                }
            });

            return $value;
        }

        self::assertSafe($key, (string) $value);

        return $value;
    }

    /**
     * @throws InvalidRequestException
     */
    private static function assertSafe(string $key, string $value): void
    {
        // Decode hex escapes (\xNN) before scanning so an attacker can't
        // smuggle a tag past the filter by encoding the leading `<`.
        $decoded = (string) preg_replace_callback(
            '#(\\\\x[0-9A-Fa-f]{2})#',
            static fn(array $m): string => chr((int) hexdec(substr($m[1], 2))),
            $value,
        );

        foreach (self::BANNED_TAGS as $tag) {
            if (preg_match('/<\s*' . $tag . '/i', $decoded) === 1) {
                throw new InvalidRequestException(
                    "Invalid data format for {$key}: banned HTML tag <{$tag}>",
                );
            }
        }
    }
}
