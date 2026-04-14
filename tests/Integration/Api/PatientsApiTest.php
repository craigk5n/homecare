<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\PatientsApi;
use HomeCare\Repository\PatientRepository;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PatientsApiTest extends DatabaseTestCase
{
    private PatientsApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new PatientsApi(new PatientRepository($this->getDb()));
    }

    public function testEmptyDatabaseReturnsEmptyData(): void
    {
        $resp = $this->api->handle([]);
        $this->assertSame('ok', $resp->status);
        $this->assertSame(200, $resp->httpStatus);
        $this->assertSame([], $resp->data);
    }

    public function testActivePatientsListed(): void
    {
        $db = $this->getDb();
        (new PatientFactory($db))->create(['name' => 'Daisy']);
        (new PatientFactory($db))->create(['name' => 'Fozzie']);

        $resp = $this->api->handle([]);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $names = [];
        foreach ($data as $row) {
            $this->assertIsArray($row);
            $names[] = $row['name'];
        }
        $this->assertSame(['Daisy', 'Fozzie'], $names);
    }

    public function testDisabledPatientHiddenByDefault(): void
    {
        $db = $this->getDb();
        (new PatientFactory($db))->create(['name' => 'Daisy']);
        (new PatientFactory($db))->create(['name' => 'Kermit', 'is_active' => 0]);

        $data = $this->api->handle([])->data;
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
    }

    public function testIncludeDisabledFlagSurfaceEveryone(): void
    {
        $db = $this->getDb();
        (new PatientFactory($db))->create(['name' => 'Daisy']);
        (new PatientFactory($db))->create(['name' => 'Kermit', 'is_active' => 0]);

        $data = $this->api->handle(['include_disabled' => '1'])->data;
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }
}
