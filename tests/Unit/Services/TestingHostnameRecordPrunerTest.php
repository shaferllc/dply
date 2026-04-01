<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use App\Services\Sites\TestingHostnameProvisioner;
use App\Services\Sites\TestingHostnameRecordPruner;
use Mockery;
use Tests\TestCase;

class TestingHostnameRecordPrunerTest extends TestCase
{
    public function test_it_finds_stale_a_records_that_are_not_attached_to_any_site(): void
    {
        $provisioner = Mockery::mock(TestingHostnameProvisioner::class);
        $provisioner->shouldReceive('configuredDomains')->once()->andReturn(['dply.cc']);

        $digitalOcean = Mockery::mock(DigitalOceanService::class);
        $digitalOcean->shouldReceive('getDomainRecords')
            ->once()
            ->with('dply.cc')
            ->andReturn([
                ['id' => 11, 'type' => 'A', 'name' => 'active-preview', 'data' => '203.0.113.10'],
                ['id' => 12, 'type' => 'A', 'name' => 'orphan-preview', 'data' => '203.0.113.11'],
                ['id' => 13, 'type' => 'CNAME', 'name' => 'ignore-me', 'data' => '@'],
            ]);

        $pruner = new class($provisioner, $digitalOcean) extends TestingHostnameRecordPruner
        {
            public function managedHostnames(): array
            {
                return ['active-preview.dply.cc'];
            }
        };

        $records = $pruner->staleRecords();

        $this->assertCount(1, $records);
        $this->assertSame('orphan-preview.dply.cc', $records[0]['hostname']);
        $this->assertSame('dply.cc', $records[0]['zone']);
        $this->assertSame(12, $records[0]['record_id']);
    }
}
