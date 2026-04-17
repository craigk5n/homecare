<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Api;

use HomeCare\Api\ApiResponse;
use HomeCare\Api\DrugLookupApi;
use HomeCare\Repository\DrugCatalogRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DrugLookupApiTest extends TestCase
{
    public function testLookupReturnsMatchingDrug(): void
    {
        $entry = [
            'id' => 1,
            'rxnorm_id' => 12345,
            'ndc' => '00071015523',
            'name' => 'Amoxicillin 500 MG Oral Capsule',
            'strength' => '500 MG',
            'dosage_form' => 'Oral Capsule',
            'ingredient_names' => 'Amoxicillin',
            'generic' => true,
        ];

        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->method('findByNdc')
            ->with('0007-1015-23')
            ->willReturn([$entry]);

        $api = new DrugLookupApi($mockRepo);
        $response = $api->handle(['ndc' => '0007-1015-23']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $this->assertNotNull($response->data);
        $this->assertCount(1, $response->data);
    }

    public function testLookupReturns404WhenNoMatch(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->method('findByNdc')->willReturn([]);

        $api = new DrugLookupApi($mockRepo);
        $response = $api->handle(['ndc' => '99999999999']);

        $this->assertSame(ApiResponse::STATUS_ERROR, $response->status);
        $this->assertSame(404, $response->httpStatus);
    }

    public function testLookupReturns400WhenNdcMissing(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);

        $api = new DrugLookupApi($mockRepo);
        $response = $api->handle([]);

        $this->assertSame(ApiResponse::STATUS_ERROR, $response->status);
        $this->assertSame(400, $response->httpStatus);
    }

    public function testLookupReturns400WhenNdcTooShort(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);

        $api = new DrugLookupApi($mockRepo);
        $response = $api->handle(['ndc' => '12345']);

        $this->assertSame(ApiResponse::STATUS_ERROR, $response->status);
        $this->assertSame(400, $response->httpStatus);
    }

    public function testLookupStripsHyphensFromNdc(): void
    {
        $mockRepo = $this->createMock(DrugCatalogRepositoryInterface::class);
        $mockRepo->expects($this->once())
            ->method('findByNdc')
            ->with('0007-1015-23')
            ->willReturn([]);

        $api = new DrugLookupApi($mockRepo);
        $api->handle(['ndc' => '0007-1015-23']);
    }
}
