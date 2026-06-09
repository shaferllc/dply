<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Servers;

use App\Actions\Servers\ListExistingProviderServers;
use App\Actions\Servers\ListServerProviderCards;
use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('provider card lists installed server role labels', function () {
    $org = Organization::factory()->create();

    ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'name' => 'Hetzner Cloud',
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'region' => 'ash',
        'meta' => ['server_role' => 'worker'],
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'region' => 'fsn1',
        'meta' => ['server_role' => 'database'],
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'region' => 'ash',
        'meta' => ['server_role' => 'worker'],
    ]);

    $hetzner = collect(ListServerProviderCards::run($org))
        ->firstWhere('id', 'hetzner');

    expect($hetzner)->not->toBeNull()
        ->and($hetzner['server_count'])->toBe(3)
        ->and($hetzner['installed_roles'])->toHaveCount(2)
        ->and(collect($hetzner['installed_roles'])->pluck('label')->all())->toBe([
            'Database server',
            'Worker server',
        ])
        ->and(collect($hetzner['installed_roles'])->firstWhere('id', 'worker')['count'])->toBe(2)
        ->and($hetzner['installed_locations'])->toHaveCount(2)
        ->and(collect($hetzner['installed_locations'])->firstWhere('region', 'ash')['count'])->toBe(2);
});

test('list existing provider servers filters by provider type', function () {
    $org = Organization::factory()->create();

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'name' => 'hetzner-worker',
        'region' => 'ash',
        'meta' => ['server_role' => 'worker'],
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'name' => 'do-app',
        'region' => 'nyc3',
        'meta' => ['server_role' => 'application'],
    ]);

    $hetznerOnly = ListExistingProviderServers::run($org, 'hetzner');

    expect($hetznerOnly)->toHaveCount(1)
        ->and($hetznerOnly[0]['name'])->toBe('hetzner-worker')
        ->and($hetznerOnly[0]['region'])->toBe('ash')
        ->and($hetznerOnly[0]['role_label'])->toBe('Worker server');

    expect(ListExistingProviderServers::make()->regionCounts($org, 'hetzner'))->toBe(['ash' => 1]);
});

test('provider card defaults missing server role to web server label', function () {
    $org = Organization::factory()->create();

    ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'provider' => 'hetzner',
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'meta' => [],
    ]);

    $hetzner = collect(ListServerProviderCards::run($org))
        ->firstWhere('id', 'hetzner');

    expect($hetzner['installed_roles'])->toBe([
        ['id' => 'application', 'label' => 'Web server', 'count' => 1],
    ]);
});
