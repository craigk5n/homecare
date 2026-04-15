<?php
declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\SignedUrl;
use HomeCare\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SignedUrlTest extends DatabaseTestCase
{
    private SignedUrl $signed;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear secret
        $this->getDb()->execute('DELETE FROM hc_config WHERE name = "signing_secret"');

        // Create instance, generates secret
        $this->signed = SignedUrl::instance();
    }

    public function testSignAndVerifyRoundtrip(): void
    {
        $params = ['patient_id' => 123, 'type' => 'csv', 'start_date' => '2026-04-01', 'end_date' => '2026-04-30'];
        $token = $this->signed->sign($params, 3600); // 1 hour

        self::assertTrue($this->signed->verify($token));
        self::assertEquals($params, $this->signed->getParams($token));
    }

    public function testVerifyExpiredFails(): void
    {
        $params = ['type' => 'ics', 'schedule_id' => 456];
        $token = $this->signed->sign($params, -3600); // Past

        self::assertFalse($this->signed->verify($token));
        self::assertNull($this->signed->getParams($token));
    }

    public function testVerifyInvalidSignatureFails(): void
    {
        $secret = $this->signed->secret; // Private? Wait, no, but for test, tamper.

        // Sign with original
        $params = ['type' => 'fhir'];
        $payload = json_encode($params + ['exp' => time() + 3600], JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $payload, $secret, true);
        $token = base64_encode($payload . $sig);

        // Tamper sig
        $tampered = base64_encode($payload . 'invalid');

        self::assertTrue($this->signed->verify($token));
        self::assertFalse($this->signed->verify($tampered));
    }

    public function testGetParamsNullOnInvalid(): void
    {
        self::assertNull($this->signed->getParams('invalid.token'));
        self::assertNull($this->signed->getParams(''));
    }

public function testSecretGeneratedAndPersisted(): void
    {
        // Clear
        $this->getDb()->execute('DELETE FROM hc_config WHERE name = "signing_secret"');

        // First instance generates
        SignedUrl::instance();

        // Get from DB
        $row = $this->getDb()->query('SELECT value FROM hc_config WHERE name = "signing_secret" LIMIT 1')[0] ?? [];
        $secret = $row['value'] ?? '';

        self::assertNotEmpty($secret);
        self::assertEquals(64, strlen($secret)); // hex 32 bytes

        // Second instance uses same, no new row
        SignedUrl::instance();

        $count = (int) ($this->getDb()->query('SELECT COUNT(*) as n FROM hc_config WHERE name = "signing_secret"')[0]['n'] ?? 0);
        self::assertEquals(1, $count);

        // Audit logged
        $audit = $this->getDb()->query('SELECT details FROM hc_audit_log WHERE action = "signedurl.secret_generated" ORDER BY created_at DESC LIMIT 1')[0]['details'] ?? '';
        self::assertNotEmpty($audit);
    }

    public function testSignDifferentInstancesSameSecret(): void
    {
        $signed1 = SignedUrl::instance();
        $signed2 = SignedUrl::instance();

        $params = ['patient_id' => 1];
        $token1 = $signed1->sign($params, 3600);

        self::assertTrue($signed2->verify($token1));
        self::assertEquals($params, $signed2->getParams($token1));
    }
}
