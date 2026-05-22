<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TestingHostnameRecordPrunerTest;
use Mockery;

use \App\Services\Sites\TestingHostnameRecordPruner;
use App\Services\DigitalOceanService;
use App\Services\Sites\TestingHostnameProvisioner;
test('it finds stale a records that are not attached to any site', function () {
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
        function managedHostnames(): array
        {
            return ['active-preview.dply.cc'];
        }
    };

    $records = $pruner->staleRecords();

    expect($records)->toHaveCount(1);
    expect($records[0]['hostname'])->toBe('orphan-preview.dply.cc');
    expect($records[0]['zone'])->toBe('dply.cc');
    expect($records[0]['record_id'])->toBe(12);
});
