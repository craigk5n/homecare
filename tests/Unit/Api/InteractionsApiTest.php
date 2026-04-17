<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Api;

use HomeCare\Api\ApiResponse;
use HomeCare\Api\InteractionsApi;
use HomeCare\Service\InteractionServiceInterface;
use PHPUnit\Framework\TestCase;

final class InteractionsApiTest extends TestCase
{
    public function testHandleReturnsInteractions(): void
    {
        $service = $this->createMock(InteractionServiceInterface::class);
        $service->method('checkForPatient')
            ->with(1, 2)
            ->willReturn([
                [
                    'ingredient_a' => 'aspirin',
                    'ingredient_b' => 'warfarin',
                    'severity' => 'major',
                    'description' => 'Bleeding risk',
                    'existing_medicine' => 'Warfarin 5mg',
                ],
            ]);

        $api = new InteractionsApi($service);
        $response = $api->handle(['patient_id' => '1', 'medicine_id' => '2']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $this->assertNotNull($response->data);
        $this->assertCount(1, $response->data);
    }

    public function testHandleReturnsEmptyWhenNoInteractions(): void
    {
        $service = $this->createMock(InteractionServiceInterface::class);
        $service->method('checkForPatient')->willReturn([]);

        $api = new InteractionsApi($service);
        $response = $api->handle(['patient_id' => '1', 'medicine_id' => '2']);

        $this->assertSame(ApiResponse::STATUS_OK, $response->status);
        $this->assertSame([], $response->data);
    }

    public function testHandleReturns400WhenMissingParams(): void
    {
        $service = $this->createMock(InteractionServiceInterface::class);
        $api = new InteractionsApi($service);

        $response = $api->handle([]);
        $this->assertSame(400, $response->httpStatus);

        $response = $api->handle(['patient_id' => '1']);
        $this->assertSame(400, $response->httpStatus);

        $response = $api->handle(['medicine_id' => '2']);
        $this->assertSame(400, $response->httpStatus);
    }
}
