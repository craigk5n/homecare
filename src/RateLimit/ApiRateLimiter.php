<?php

declare(strict_types=1);

namespace HomeCare\RateLimit;

use HomeCare\Database\DatabaseInterface;

final class ApiRateLimiter
{
    public function __construct(
        private DatabaseInterface $db,
    ) {}

    private function getCurrentWindow(): int
    {
        return (int) (floor(time() / 60) * 60);
    }

    public function isUnderLimit(string $ip, int $limit, string $bucket): bool
    {
        $window = $this->getCurrentWindow();

        $rows = $this->db->query(
            'SELECT `count` FROM hc_api_rate_limit WHERE ip = ? AND bucket = ? AND window_start = ? LIMIT 1',
            [$ip, $bucket, $window],
        );

        $current = $rows ? (int) $rows[0]['count'] : 0;

        return $current < $limit;
    }

    public function increment(string $ip, string $bucket): void
    {
        $window = $this->getCurrentWindow();

        $rows = $this->db->query(
            'SELECT `count` FROM hc_api_rate_limit WHERE ip = ? AND bucket = ? AND window_start = ? LIMIT 1',
            [$ip, $bucket, $window],
        );

        $current = $rows ? (int) $rows[0]['count'] : 0;

        $new_count = $current + 1;

        if ($rows) {
            $this->db->execute(
                'UPDATE hc_api_rate_limit SET `count` = ? WHERE ip = ? AND bucket = ? AND window_start = ?',
                [$new_count, $ip, $bucket, $window],
            );
        } else {
            $this->db->execute(
                'INSERT INTO hc_api_rate_limit (ip, bucket, window_start, `count`) VALUES (?, ?, ?, ?)',
                [$ip, $bucket, $window, $new_count],
            );
        }
    }

    public function checkAndIncrement(string $ip, int $limit, string $bucket): bool
    {
        if (!$this->isUnderLimit($ip, $limit, $bucket)) {
            return false;
        }

        $this->increment($ip, $bucket);

        return true;
    }
}
