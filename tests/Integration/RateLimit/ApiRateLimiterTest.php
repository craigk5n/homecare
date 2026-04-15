<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\RateLimit;

use HomeCare\Database\DatabaseInterface;
use HomeCare\RateLimit\ApiRateLimiter;
use HomeCare\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApiRateLimiter::class)]
final class ApiRateLimiterTest extends DatabaseTestCase
{
    private ApiRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new ApiRateLimiter($this->getDb());

        $this->getDb()->execute('CREATE TABLE IF NOT EXISTS hc_api_rate_limit (
            ip TEXT NOT NULL,
            bucket TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (ip, bucket, window_start)
        )');

        $this->getDb()->execute("INSERT OR IGNORE INTO hc_config (setting, value) VALUES ('api_rate_limit_rpm', '5')");
        $this->getDb()->execute("INSERT OR IGNORE INTO hc_config (setting, value) VALUES ('api_rate_limit_authenticated_rpm', '10')");
    }

    public function test_is_under_limit_when_no_row(): void
    {
        $this->assertTrue($this->limiter->isUnderLimit('1.2.3.4', 3, 'test_bucket'));
    }

    public function test_is_under_limit_when_below_limit(): void
    {
        $this->limiter->increment('1.2.3.4', 'test_bucket');

        $this->assertTrue($this->limiter->isUnderLimit('1.2.3.4', 3, 'test_bucket'));
    }

    public function test_not_under_limit_when_at_or_over(): void
    {
        $this->limiter->increment('1.2.3.4', 'test_bucket');
        $this->limiter->increment('1.2.3.4', 'test_bucket');
        $this->limiter->increment('1.2.3.4', 'test_bucket');

        $this->assertFalse($this->limiter->isUnderLimit('1.2.3.4', 3, 'test_bucket'));
    }

    public function test_check_and_increment_returns_true_when_under(): void
    {
        $this->assertTrue($this->limiter->checkAndIncrement('1.2.3.4', 3, 'test_bucket'));
    }

    public function test_check_and_increment_returns_false_when_over(): void
    {
        $this->limiter->checkAndIncrement('1.2.3.4', 1, 'test_bucket'); // inc to 1, but limit 1, wait no: check <1 (0<1 true), inc to 1

        $this->assertFalse($this->limiter->checkAndIncrement('1.2.3.4', 1, 'test_bucket')); // 1 <1 false
    }

    public function test_different_buckets_are_independent(): void
    {
        $this->limiter->checkAndIncrement('1.2.3.4', 1, 'bucket1'); // inc bucket1 to 1

        $this->assertTrue($this->limiter->isUnderLimit('1.2.3.4', 5, 'bucket2')); // bucket2 0 <5 true
    }

    // Test config - make public or use reflection if needed
    // For now, skip private method test
    

    // Skip
    

    // Skip
    
}
