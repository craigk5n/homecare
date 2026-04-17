<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;

/**
 * HC-093: verify live Set-Cookie headers on the login flow.
 *
 * Requires Apache to be serving the project at
 * `HOMECARE_BASE_URL` (default `http://localhost/homecare`) with
 * the `cknudsen`/`cknudsen` dev account seeded. Skipped when the
 * base URL isn't reachable so CI on a container without Apache
 * still passes.
 *
 * The test parses the raw `Set-Cookie:` headers with a tiny
 * hand-rolled tokeniser rather than relying on a stdlib parser —
 * PHP's `setcookie()` output is simple enough and we want
 * per-attribute assertions ("HttpOnly present").
 */
final class CookieFlagsTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('HOMECARE_BASE_URL') !== false
            ? (string) getenv('HOMECARE_BASE_URL')
            : 'http://localhost/homecare';

        // Probe — skip when the server isn't reachable.
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        $body = @file_get_contents($this->baseUrl . '/login.php', false, $context);
        if ($body === false) {
            $this->markTestSkipped("Apache at {$this->baseUrl} not reachable");
        }
    }

    public function testSessionCookieHasHardenedAttributes(): void
    {
        $headers = $this->postLogin(remember: false);
        $cookie = $this->firstCookieNamed($headers, 'PHPSESSID');

        $this->assertNotNull($cookie, 'PHPSESSID must be set on login');
        $this->assertTrue($cookie['httponly']);
        $this->assertSame('Lax', $cookie['samesite']);
        $this->assertSame('/', $cookie['path']);
        // Secure depends on scheme; assert consistency rather than hardcoding.
        $isHttps = str_starts_with($this->baseUrl, 'https://');
        $this->assertSame($isHttps, $cookie['secure']);
    }

    public function testRememberMeCookieHasHardenedAttributes(): void
    {
        $headers = $this->postLogin(remember: true);
        $cookie = $this->firstCookieNamed($headers, 'hc_remember');

        $this->assertNotNull($cookie, 'hc_remember must be set when remember=1');
        $this->assertTrue($cookie['httponly']);
        $this->assertSame('Lax', $cookie['samesite']);
        $this->assertSame('/', $cookie['path']);
        $isHttps = str_starts_with($this->baseUrl, 'https://');
        $this->assertSame($isHttps, $cookie['secure']);
        // 365-day lifetime → an Expires attribute at least ~360 days out.
        $this->assertNotNull($cookie['expires']);
        $expiresUnix = strtotime((string) $cookie['expires']);
        $this->assertNotFalse($expiresUnix);
        $this->assertGreaterThan(
            time() + 360 * 86400,
            $expiresUnix,
            'remember-me cookie should live ~365 days',
        );
    }

    /**
     * @return list<string> raw Set-Cookie header values
     */
    private function postLogin(bool $remember): array
    {
        $body = http_build_query([
            'login'    => 'cknudsen',
            'password' => 'cknudsen',
            'remember' => $remember ? '1' : '',
        ]);
        $ch = curl_init($this->baseUrl . '/login.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $response = curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if (!is_string($response)) {
            $this->fail('login POST failed');
        }
        $rawHeaders = substr($response, 0, $headerSize);
        $lines = preg_split('/\r\n|\r|\n/', $rawHeaders) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $out[] = trim(substr($line, 11));
            }
        }

        return $out;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{name:string,value:string,path:?string,expires:?string,secure:bool,httponly:bool,samesite:?string}|null
     */
    private function firstCookieNamed(array $headers, string $name): ?array
    {
        foreach ($headers as $raw) {
            $parts = array_map('trim', explode(';', $raw));
            [$first] = $parts;
            if (!str_contains($first, '=')) {
                continue;
            }
            [$cName, $cValue] = explode('=', $first, 2);
            if ($cName !== $name) {
                continue;
            }

            $attrs = [
                'name'     => $cName,
                'value'    => $cValue,
                'path'     => null,
                'expires'  => null,
                'secure'   => false,
                'httponly' => false,
                'samesite' => null,
            ];
            for ($i = 1; $i < count($parts); $i++) {
                $attr = $parts[$i];
                if (stripos($attr, 'path=') === 0) {
                    $attrs['path'] = substr($attr, 5);
                } elseif (stripos($attr, 'expires=') === 0) {
                    $attrs['expires'] = substr($attr, 8);
                } elseif (strcasecmp($attr, 'secure') === 0) {
                    $attrs['secure'] = true;
                } elseif (strcasecmp($attr, 'httponly') === 0) {
                    $attrs['httponly'] = true;
                } elseif (stripos($attr, 'samesite=') === 0) {
                    $attrs['samesite'] = substr($attr, 9);
                }
            }

            return $attrs;
        }

        return null;
    }
}
