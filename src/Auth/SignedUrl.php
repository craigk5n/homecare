<?php
/**
 * Token-signed URL generator and verifier for shareable feeds and exports.
 *
 * Uses HMAC-SHA256 over JSON-serialized params + expiration timestamp.
 * Secret fetched from hc_config.signing_secret (generated if missing).
 */

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DbiAdapter;
use const JSON_UNESCAPED_SLASHES;

final class SignedUrl
{
    public function __construct(private readonly string $secret) {}

    /**
     * Sign parameters with TTL in seconds.
     *
     * @param array&lt;string, int|string&gt; $params
     */
    public function sign(array $params, int $ttl): string
    {
        $payload = json_encode($params + ['exp' => time() + $ttl], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payload, $this->secret, true);
        return base64_encode($payload . $signature);
    }


    public function verify(string $token): bool
    {
$decoded = \base64_decode($token, true);
if ($decoded === false) {
    return false;
}
$dotPos = \strrpos($decoded, '.');
if ($dotPos === false) {
    return false;
}
$payload = \substr($decoded, 0, $dotPos);
$signature = \substr($decoded, $dotPos + 1);

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
     */
    public function getParams(string $token): ?array
    {
        if (!$this->verify($token)) {
            return null;
        }

$decoded = \base64_decode($token, true);
if ($decoded === false) {
    return null;
}
$dotPos = \strrpos($decoded, '.');
if ($dotPos === false) {
    return null;
}
$payload = \substr($decoded, 0, $dotPos);

return \json_decode($payload, true);
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
        static $secret = null;

        if ($secret !== null) {
            return $secret;
        }

        // Fetch or generate
        require_once __DIR__ . '/../includes/init.php'; // Globals
        $result = dbi_execute('SELECT value FROM hc_config WHERE name = "signing_secret"');
        $row = dbi_fetch_row($result);
        if ($row && $row[0]) {
            $secret = $row[0];
        } else {
            // Generate 32-byte hex
            $secret = \bin2hex(\random_bytes(32));
            dbi_execute('INSERT INTO hc_config (name, value) VALUES ("signing_secret", ?)', [$secret]);
            // Require homecare for audit
            require_once __DIR__ . '/../includes/homecare.php';
            audit_log('signedurl.secret_generated', 'config'); // Log once
        }

        return $secret;
    }
}