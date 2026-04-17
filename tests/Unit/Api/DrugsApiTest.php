<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Api;

use HomeCare\Api\ApiResponse;
use HomeCare\Api\DrugsApi;
use HomeCare\Repository\DrugCatalogRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DrugsApiTest extends TestCase
{
    public function testSearchReturnsMatchingDrugs(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->method('search')
            ->with('amox', 20)
            ->willReturn([
                [
                    'id' => 1,
                    'rxnorm_id' => 12345,
                    'name' => 'Amoxicillin 500 MG Oral Capsule',
                    'strength' => '500 MG',
                    'dosage_form' => 'Oral Capsule',
                    'ingredient_names' => 'Amoxicillin',
                    'generic' => true,
                ],
            ]);

        $api = new DrugsApi($mockRepo);
        $response = $api->handle(['q' => 'amox']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $data = $response->data;
        $this->assertNotNull($data);
        $this->assertCount(1, $data);
        $first = $data[0];
        $this->assertIsArray($first);
        $this->assertSame('Amoxicillin 500 MG Oral Capsule', $first['name']);
    }

    public function testSearchReturnEmptyForShortQuery(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->expects($this->never())->method('search');

        $api = new DrugsApi($mockRepo);
        $response = $api->handle(['q' => 'a']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $this->assertSame([], $response->data);
    }

    public function testSearchReturnEmptyForMissingQuery(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->expects($this->never())->method('search');

        $api = new DrugsApi($mockRepo);
        $response = $api->handle([]);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $this->assertSame([], $response->data);
    }

    public function testSearchRespectsCustomLimit(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->method('search')
            ->with('aspirin', 5)
            ->willReturn([]);

        $api = new DrugsApi($mockRepo);
        $response = $api->handle(['q' => 'aspirin', 'limit' => '5']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
    }

    public function testSearchClampsLimitToMaxFifty(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->expects($this->once())
            ->method('search')
            ->with('test', 50)
            ->willReturn([]);

        $api = new DrugsApi($mockRepo);
        $response = $api->handle(['q' => 'test', 'limit' => '999']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
    }
}
