<?php
/**
 * Token-signed URL generator and verifier for shareable feeds and exports.
 *
 * Uses HMAC-SHA256 over JSON-serialized params + expiration timestamp.
 * Secret fetched from hc_config.signing_secret (generated if missing).
 *
 * Token format: base64url(payload) . '.' . base64url(signature)
 * where payload is JSON and signature is HMAC-SHA256 of the payload.
 */

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DbiAdapter;
use const JSON_UNESCAPED_SLASHES;

final class SignedUrl
{
    private static ?string $cachedSecret = null;

    public function __construct(private readonly string $secret) {}

    /**
     * Sign parameters with TTL in seconds.
     *
     * @param array<string, int|string> $params
     */
    public function sign(array $params, int $ttl): string
    {
        $payload = json_encode($params + ['exp' => time() + $ttl], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Failed to JSON-encode params for signing.');
        }
        $signature = hash_hmac('sha256', $payload, $this->secret, true);
        return base64_encode($payload) . '.' . base64_encode($signature);
    }


    public function verify(string $token): bool
    {
        $dotPos = \strpos($token, '.');
        if ($dotPos === false) {
            return false;
        }

        $payloadB64 = \substr($token, 0, $dotPos);
        $sigB64 = \substr($token, $dotPos + 1);

        $payload = \base64_decode($payloadB64, true);
        $signature = \base64_decode($sigB64, true);

        if ($payload === false || $signature === false) {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $payload, $this->secret, true);

        if (!\hash_equals($expectedSig, $signature)) {
            return false;
        }

        $data = \json_decode($payload, true);
        if (!is_array($data) || !isset($data['exp']) || \time() > $data['exp']) {
            return false;
        }

        return true;
    }

    /**
     * Extract verified params from token (null if invalid/expired).
     *
     * @return array<string, mixed>|null
     */
    public function getParams(string $token): ?array
    {
        if (!$this->verify($token)) {
            return null;
        }

        $dotPos = \strpos($token, '.');
        if ($dotPos === false) {
            return null;
        }

        $payloadB64 = \substr($token, 0, $dotPos);
        $payload = \base64_decode($payloadB64, true);

        if ($payload === false) {
            return null;
        }

        $decoded = \json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Ensure string keys (JSON objects always have string keys when decoded as associative)
        $data = [];
        foreach ($decoded as $k => $v) {
            $data[(string) $k] = $v;
        }

        return $data;
    }

    /**
     * Get singleton instance with secret from hc_config.
     */
    public static function instance(): self
    {
        $secret = self::getSecret();
        return new self($secret);
    }

    private static function getSecret(): string
    {
        if (self::$cachedSecret !== null) {
            return self::$cachedSecret;
        }

        $db = new DbiAdapter();

        $rows = $db->query('SELECT value FROM hc_config WHERE setting = ?', ['signing_secret']);
        if (!empty($rows) && !empty($rows[0]['value'])) {
            self::$cachedSecret = (string) $rows[0]['value'];
        } else {
            // Generate 32-byte hex
            self::$cachedSecret = \bin2hex(\random_bytes(32));
            $db->execute('INSERT INTO hc_config (setting, value) VALUES (?, ?)', ['signing_secret', self::$cachedSecret]);
            // Require homecare for audit
            if (function_exists('audit_log')) {
                audit_log('signedurl.secret_generated', 'config');
            }
        }

        return self::$cachedSecret;
    }
}
