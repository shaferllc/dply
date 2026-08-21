<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Serverless\AssetsPanelTest;

use App\Models\Organization;
use App\Models\ServerlessUsageSnapshot;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Livewire\AssetsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $this->site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $this->user->id,
        'meta' => ['serverless' => [
            'proxy_slug' => 'orders-a1b2c3d4',
            'asset_url' => 'https://orders-a1b2c3d4-assets.dply-serverless.cloud',
            'assets_file_count' => 12,
            'assets_published_at' => now()->subHour()->toIso8601String(),
            'assets' => ['storage_bytes' => 8 * 1024 ** 2, 'storage_measured_at' => now()->toIso8601String()],
        ]],
    ]);

    config([
        'dply.serverless.usage_billing.included_asset_storage_gb_per_function' => 1,
        'dply.serverless.usage_billing.included_asset_egress_gb_per_function' => 100,
    ]);
});

test('it renders where assets are served from', function () {
    Livewire::actingAs($this->user)
        ->test(AssetsPanel::class, ['site' => $this->site])
        ->assertOk()
        ->assertSee('Front-end assets')
        ->assertSee('orders-a1b2c3d4-assets.dply-serverless.cloud');
});

test('it renders for a function that has never published', function () {
    $site = Site::factory()->create([
        'server_id' => $this->site->server_id,
        'organization_id' => $this->site->organization_id,
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    Livewire::actingAs($this->user)
        ->test(AssetsPanel::class, ['site' => $site])
        ->assertOk()
        ->assertSee('Nothing published yet');
});

test('it shows the over-allowance state without implying an outage', function () {
    ServerlessUsageSnapshot::query()->create([
        'organization_id' => $this->site->organization_id,
        'site_id' => $this->site->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->startOfMonth()->toDateString(),
        'source' => ServerlessUsageSnapshot::SOURCE_FUNCTION_INVOCATIONS,
        'asset_storage_bytes' => 0,
        'asset_bytes_egress' => 150 * 1024 ** 3,
    ]);

    Livewire::actingAs($this->user)
        ->test(AssetsPanel::class, ['site' => $this->site])
        ->assertOk()
        ->assertSee('Over allowance')
        // The whole point of the guardrail being advisory.
        ->assertSee('Assets keep serving normally');
});

test('attaching a domain rejects a hostname that is not one', function () {
    Livewire::actingAs($this->user)
        ->test(AssetsPanel::class, ['site' => $this->site])
        ->set('newHostname', 'not-a-hostname')
        ->call('attachDomain')
        ->assertOk();

    expect($this->site->fresh()->serverlessConfig()['assets']['custom_hostnames'] ?? [])->toBe([]);
});

test('a pending custom domain is shown but is not yet billable', function () {
    $this->site->forceFill(['meta' => ['serverless' => [
        'proxy_slug' => 'orders-a1b2c3d4',
        'assets' => [
            'custom_hostname_details' => [[
                'hostname' => 'cdn.acme.com',
                'status' => 'pending',
                'origin' => 'orders-a1b2c3d4-assets.dply-serverless.cloud',
            ]],
            // Deliberately empty: only ACTIVE hostnames are billable, since
            // ASSET_URL has not moved to the custom one yet.
            'custom_hostnames' => [],
        ],
    ]]])->save();

    Livewire::actingAs($this->user)
        ->test(AssetsPanel::class, ['site' => $this->site->fresh()])
        ->assertOk()
        ->assertSee('cdn.acme.com')
        ->assertSee('Validating');
});
