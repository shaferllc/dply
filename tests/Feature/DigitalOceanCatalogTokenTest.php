<?php

namespace Tests\Feature\DigitalOceanCatalogTokenTest;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('resolve catalog uses digitalocean token when no credential selected', function () {
    config(['services.digitalocean.token' => 'dop_v1_test_catalog_token']);

    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::response([
            'regions' => [
                [
                    'slug' => 'nyc3',
                    'name' => 'New York 3',
                    'available' => true,
                ],
                [
                    'slug' => 'legacy',
                    'name' => 'Legacy',
                    'available' => false,
                ],
            ],
        ], 200),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [
                [
                    'slug' => 's-1vcpu-1gb',
                    'memory' => 1024,
                    'vcpus' => 1,
                    'disk' => 25,
                    'price_monthly' => 6,
                    'available' => true,
                ],
                [
                    'slug' => 'unavailable-size',
                    'memory' => 512,
                    'vcpus' => 1,
                    'disk' => 10,
                    'price_monthly' => 4,
                    'available' => false,
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', '', '');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('nyc3');
    expect($catalog['sizes'])->toHaveCount(1);
    $this->assertStringContainsString('s-1vcpu-1gb', $catalog['sizes'][0]['label']);
    $this->assertStringContainsString('$6', $catalog['sizes'][0]['label']);
});
