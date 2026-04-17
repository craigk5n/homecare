<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\WebhookLogRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class WebhookLogRepositoryTest extends DatabaseTestCase
{
    private WebhookLogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new WebhookLogRepository($this->getDb());
    }

    public function testInsertAndSearch(): void
    {
        $this->repo->insert([
            'message_id' => 'hc-test-001',
            'url' => 'https://example.com/hook',
            'request_body' => '{"title":"test"}',
            'http_status' => 200,
            'response_body' => 'ok',
            'error_message' => null,
            'attempt' => 1,
            'max_attempts' => 4,
            'elapsed_ms' => 42,
            'success' => true,
        ]);

        $rows = $this->repo->search([], 1, 50);

        self::assertCount(1, $rows);
        self::assertSame('hc-test-001', $rows[0]['message_id']);
        self::assertSame(200, $rows[0]['http_status']);
        self::assertTrue($rows[0]['success']);
        self::assertSame(42, $rows[0]['elapsed_ms']);
    }

    public function testFilterBySuccess(): void
    {
        $this->insertSample('msg-ok', true, 200);
        $this->insertSample('msg-fail', false, null);

        $okRows = $this->repo->search(['success' => '1']);
        self::assertCount(1, $okRows);
        self::assertSame('msg-ok', $okRows[0]['message_id']);

        $failRows = $this->repo->search(['success' => '0']);
        self::assertCount(1, $failRows);
        self::assertSame('msg-fail', $failRows[0]['message_id']);
    }

    public function testFilterByHttpStatus(): void
    {
        $this->insertSample('msg-200', true, 200);
        $this->insertSample('msg-500', false, 500);

        $rows = $this->repo->search(['http_status' => '500']);
        self::assertCount(1, $rows);
        self::assertSame('msg-500', $rows[0]['message_id']);
    }

    public function testFilterByDateRange(): void
    {
        $this->insertSample('msg-old', true, 200);

        // Manually update the timestamp to be old.
        $this->getDb()->execute(
            "UPDATE hc_webhook_log SET created_at = '2026-01-01 10:00:00' WHERE message_id = 'msg-old'",
            [],
        );

        $this->insertSample('msg-new', true, 200);

        $rows = $this->repo->search([
            'date_from' => '2026-04-01',
            'date_to' => '2026-12-31',
        ]);
        self::assertCount(1, $rows);
        self::assertSame('msg-new', $rows[0]['message_id']);
    }

    public function testCountMatchesSearch(): void
    {
        $this->insertSample('a', true, 200);
        $this->insertSample('b', false, 500);
        $this->insertSample('c', true, 200);

        self::assertSame(3, $this->repo->count([]));
        self::assertSame(2, $this->repo->count(['success' => '1']));
    }

    public function testPagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertSample("msg-{$i}", true, 200);
        }

        $page1 = $this->repo->search([], 1, 2);
        $page2 = $this->repo->search([], 2, 2);
        $page3 = $this->repo->search([], 3, 2);

        self::assertCount(2, $page1);
        self::assertCount(2, $page2);
        self::assertCount(1, $page3);
    }

    public function testGetDistinctStatuses(): void
    {
        $this->insertSample('a', true, 200);
        $this->insertSample('b', false, 500);
        $this->insertSample('c', false, null);

        $statuses = $this->repo->getDistinctStatuses();
        self::assertSame([200, 500], $statuses);
    }

    public function testFailedAttemptWithErrorMessage(): void
    {
        $this->repo->insert([
            'message_id' => 'msg-err',
            'url' => 'https://example.com/hook',
            'request_body' => '{}',
            'http_status' => null,
            'response_body' => null,
            'error_message' => 'Connection timed out',
            'attempt' => 3,
            'max_attempts' => 4,
            'elapsed_ms' => 5000,
            'success' => false,
        ]);

        $rows = $this->repo->search([]);
        self::assertCount(1, $rows);
        self::assertSame('Connection timed out', $rows[0]['error_message']);
        self::assertSame(3, $rows[0]['attempt']);
        self::assertNull($rows[0]['http_status']);
    }

    private function insertSample(string $messageId, bool $success, ?int $status): void
    {
        $this->repo->insert([
            'message_id' => $messageId,
            'url' => 'https://example.com/hook',
            'request_body' => '{"title":"test"}',
            'http_status' => $status,
            'response_body' => $success ? 'ok' : null,
            'error_message' => $success ? null : 'Error',
            'attempt' => 1,
            'max_attempts' => 4,
            'elapsed_ms' => 50,
            'success' => $success,
        ]);
    }
}
