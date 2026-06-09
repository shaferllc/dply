<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites\Promote;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Sites\Promote\SitePromoteHostnameResolver;
use App\Services\Sites\Promote\SitePromotePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('promote hostname resolver returns unique preview hostname', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $sourceServer = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $destServer = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

    $source = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $sourceServer->id,
        'user_id' => $user->id,
        'slug' => 'marketing-api',
    ]);

    $hostname = app(SitePromoteHostnameResolver::class)->resolve($source, $destServer);

    expect($hostname)->toContain('.');
    expect(SiteDomain::query()->where('hostname', $hostname)->exists())->toBeFalse();
});

test('promote planner builds cutover steps for destination with meta', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'ip_address' => '203.0.113.10']);

    $source = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Production',
    ]);

    SiteDomain::query()->create([
        'site_id' => $source->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
    ]);

    $standby = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'meta' => [
            'promote' => [
                'source_site_id' => (string) $source->id,
                'source_production_hostname' => 'app.example.com',
                'cutover_status' => 'pending_preview',
            ],
        ],
    ]);

    SiteDomain::query()->create([
        'site_id' => $standby->id,
        'hostname' => 'marketing-api-standby.on-dply.site',
        'is_primary' => true,
    ]);

    $steps = app(SitePromotePlanner::class)->cutoverSteps($standby, $source);

    expect($steps)->not->toBeEmpty();
    expect(collect($steps)->first()['text'])->toContain('smoke-test');
});
