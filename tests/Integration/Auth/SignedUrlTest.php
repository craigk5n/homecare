<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\SignedUrl;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class SignedUrlTest extends DatabaseTestCase
{
    private SignedUrl $signed;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear secret cache by creating a fresh instance with a known secret
        $this->getDb()->execute('DELETE FROM hc_config WHERE setting = "signing_secret"');

        // Create instance using a known secret for deterministic tests
        $this->signed = new SignedUrl('integration-test-secret-2026');
    }

    public function testSignAndVerifyRoundtrip(): void
    {
        $params = ['patient_id' => 123, 'type' => 'csv', 'start_date' => '2026-04-01', 'end_date' => '2026-04-30'];
        $token = $this->signed->sign($params, 3600); // 1 hour

        self::assertTrue($this->signed->verify($token));
        $decoded = $this->signed->getParams($token);
        self::assertNotNull($decoded);
        self::assertEquals($params['patient_id'], $decoded['patient_id']);
        self::assertEquals($params['type'], $decoded['type']);
        self::assertEquals($params['start_date'], $decoded['start_date']);
        self::assertEquals($params['end_date'], $decoded['end_date']);
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
        // Sign with original
        $params = ['type' => 'fhir'];
        $token = $this->signed->sign($params, 3600);

        // Tamper: replace signature part with garbage
        $dotPos = strpos($token, '.');
        self::assertNotFalse($dotPos);
        $payloadPart = substr($token, 0, $dotPos);
        $tampered = $payloadPart . '.' . base64_encode('invalidsignature');

        self::assertTrue($this->signed->verify($token));
        self::assertFalse($this->signed->verify($tampered));
    }

    public function testGetParamsNullOnInvalid(): void
    {
        self::assertNull($this->signed->getParams('invalid.token'));
        self::assertNull($this->signed->getParams(''));
    }

    // testSecretGeneratedAndPersisted is omitted here because SignedUrl::instance()
    // uses DbiAdapter which requires the legacy dbi4php layer (not available in
    // the in-memory SQLite integration test environment). The sign/verify logic
    // is fully covered by the other tests above.

    public function testSignDifferentInstancesSameSecret(): void
    {
        $signed1 = new SignedUrl('shared-test-secret');
        $signed2 = new SignedUrl('shared-test-secret');

        $params = ['patient_id' => 1];
        $token1 = $signed1->sign($params, 3600);

        self::assertTrue($signed2->verify($token1));
        $decoded = $signed2->getParams($token1);
        self::assertNotNull($decoded);
        self::assertEquals(1, $decoded['patient_id']);
    }
}
