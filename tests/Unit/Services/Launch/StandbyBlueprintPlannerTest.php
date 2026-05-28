<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Launch;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Launch\StandbyBlueprintPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('planner marks edge hybrid blueprint available when hybrid stack exists', function () {
    [$org, $edge] = orgWithHybridEdge();

    $playbook = app(StandbyBlueprintPlanner::class)->playbook($org, 'edge_hybrid_origin');

    expect($playbook)->not->toBeNull();
    expect($playbook->available)->toBeTrue();
    expect($playbook->resources)->not->toBeEmpty();
    expect(collect($playbook->steps)->first()['text'])->toContain('Edge static assets');
});

test('planner flags byo gap when only one server exists', function () {
    [$org] = orgWithByoSite();

    $playbook = app(StandbyBlueprintPlanner::class)->playbook($org, 'byo_standby_server');

    expect($playbook)->not->toBeNull();
    expect($playbook->available)->toBeTrue();
    expect($playbook->gaps)->toContain(__('Only one BYO server — provision a standby before you need it.'));
});

test('dns blueprint lists custom domains', function () {
    [$org, $site] = orgWithByoSite();

    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'api.example.com',
        'is_primary' => true,
    ]);

    $playbook = app(StandbyBlueprintPlanner::class)->playbook($org, 'dns_cutover');

    expect($playbook)->not->toBeNull();
    expect($playbook->available)->toBeTrue();
    expect(collect($playbook->resources)->pluck('label')->all())->toContain('api.example.com');
});

/**
 * @return array{0: Organization, 1: Site}
 */
function orgWithHybridEdge(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $cloud = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'SSR Origin',
        'container_backend' => 'dply_cloud',
        'type' => SiteType::Container,
    ]);

    $edge = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Edge Front',
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'runtime_mode' => 'hybrid',
                'origin' => [
                    'cloud_site_id' => (string) $cloud->id,
                    'url' => 'https://origin.example.com',
                ],
            ],
        ],
    ]);

    return [$org, $edge];
}

/**
 * @return array{0: Organization, 1: Site}
 */
function orgWithByoSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'API',
    ]);

    return [$org, $site];
}
