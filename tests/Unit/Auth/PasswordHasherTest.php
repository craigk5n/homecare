<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerifyRoundTrip(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('secret123');

        $this->assertNotSame('secret123', $hash);
        $this->assertTrue($hasher->verify('secret123', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }

    public function testHashIsSaltedUniquePerCall(): void
    {
        $hasher = new PasswordHasher();
        $this->assertNotSame($hasher->hash('x'), $hasher->hash('x'));
    }

    public function testVerifyRejectsEmptyHash(): void
    {
        $this->assertFalse((new PasswordHasher())->verify('anything', ''));
    }

    public function testNeedsRehashForMd5LegacyHash(): void
    {
        $hasher = new PasswordHasher();
        // 32-char hex MD5 is the pre-native-hash format used by the legacy
        // WebCalendar code. Should always be flagged for upgrade.
        $md5 = md5('secret');
        $this->assertTrue($hasher->needsRehash($md5));
    }

    public function testNeedsRehashFalseForCurrentAlgo(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('secret');
        $this->assertFalse($hasher->needsRehash($hash));
    }
}
