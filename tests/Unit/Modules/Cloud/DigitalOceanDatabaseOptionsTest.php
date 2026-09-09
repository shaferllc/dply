<?php

declare(strict_types=1);

use App\Models\ProviderCredential;
use App\Modules\Providers\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;

test('managed redis regions come from the database options catalog', function (): void {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'valkey' => [
                    'regions' => ['nyc1', 'sfo2', 'sfo3', 'ams3', 'atl1', 'ric1'],
                    'versions' => ['8'],
                    'default_version' => '8',
                    'layouts' => [
                        ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb', 'db-s-4vcpu-8gb', 'm-2vcpu-16gb']],
                        ['num_nodes' => 3, 'sizes' => ['db-s-2vcpu-4gb']],
                    ],
                ],
                'redis' => ['regions' => ['ams3', 'atl1', 'nyc1', 'nyc2', 'nyc3', 'sfo2', 'sfo3']],
                'pg' => [
                    'regions' => ['sfo2', 'sfo3', 'nyc3', 'atl1'],
                    'versions' => ['16', '15'],
                    'default_version' => '16',
                ],
            ],
        ], 200),
    ]);

    $credential = ProviderCredential::factory()->make([
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
    $service = new DigitalOceanService($credential);

    expect($service->getDatabaseEngineRegions('redis'))->toBe(['nyc1', 'sfo2', 'sfo3', 'ams3', 'atl1', 'ric1'])
        ->and($service->getDatabaseEngineSizes('redis'))->toBe(['db-s-1vcpu-1gb', 'db-s-4vcpu-8gb', 'm-2vcpu-16gb'])
        ->and($service->getDatabaseEngineVersions('redis'))->toBe(['8'])
        ->and($service->getDatabaseEngineDefaultVersion('redis'))->toBe('8')
        ->and($service->getDatabaseEngineRegions('postgres'))->toBe(['sfo2', 'sfo3', 'nyc3', 'atl1'])
        ->and($service->getDatabaseEngineVersions('postgres'))->toBe(['16', '15']);
});

test('managed redis never reads the leftover redis options dump', function (): void {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'redis' => [
                    'regions' => ['nyc2', 'mkc1'],
                    'versions' => ['7'],
                    'layouts' => [
                        ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb']],
                    ],
                ],
            ],
        ], 200),
    ]);

    $credential = ProviderCredential::factory()->make([
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
    $service = new DigitalOceanService($credential);

    expect($service->getDatabaseEngineRegions('redis'))->toBe([])
        ->and($service->getDatabaseEngineSizes('redis'))->toBe([])
        ->and($service->getDatabaseEngineVersions('redis'))->toBe([]);
});
