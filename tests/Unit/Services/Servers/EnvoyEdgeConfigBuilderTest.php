<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\EnvoyEdgeConfigBuilderTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Servers\EnvoyEdgeConfigBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeUserWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('listener binds to provided port', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(new Collection([]), 8080, fn ($s) => 20000);

    expect($out)->toMatch('/port_value: 8080\b/');
    $this->assertDoesNotMatchRegularExpression('/port_value: 80\b/', $out);
});

test('cutover listener binds to 80', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    expect($out)->toMatch('/port_value: 80\b/');
    $this->assertDoesNotMatchRegularExpression('/port_value: 8080\b/', $out);
});

test('emits virtual host and cluster per site', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);

    $site1 = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'alpha-site',
        'runtime' => 'php',
    ]);
    $site2 = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'beta-site',
        'runtime' => 'node',
    ]);

    SiteDomain::query()->create([
        'site_id' => $site1->id,
        'hostname' => 'alpha.example.com',
        'is_primary' => true,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site2->id,
        'hostname' => 'beta.example.com',
        'is_primary' => true,
    ]);

    $sites = new Collection([$site1->fresh(), $site2->fresh()]);
    $portMap = [$site1->id => 25001, $site2->id => 25002];

    $out = app(EnvoyEdgeConfigBuilder::class)->build(
        $sites,
        80,
        fn (Site $s): int => $portMap[$s->id],
    );

    $id1 = preg_replace('/[^A-Za-z0-9_]/', '_', $site1->webserverConfigBasename());
    $id2 = preg_replace('/[^A-Za-z0-9_]/', '_', $site2->webserverConfigBasename());

    $this->assertStringContainsString('"alpha.example.com"', $out);
    $this->assertStringContainsString('"beta.example.com"', $out);
    $this->assertStringContainsString('cluster: cluster_'.$id1, $out);
    $this->assertStringContainsString('cluster: cluster_'.$id2, $out);
    $this->assertStringContainsString('name: cluster_'.$id1, $out);
    $this->assertStringContainsString('port_value: 25001', $out);
    $this->assertStringContainsString('name: cluster_'.$id2, $out);
    $this->assertStringContainsString('port_value: 25002', $out);
});

test('marks output as dply managed', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    $this->assertStringContainsString('Managed by Dply', $out);
    $this->assertStringContainsString('do NOT hand-edit', $out);
});

test('emits 503 fallback for unmatched hosts', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    $this->assertStringContainsString('name: dply_unmatched', $out);
    $this->assertStringContainsString('direct_response:', $out);
    $this->assertStringContainsString('status: 503', $out);
    $this->assertStringContainsString('no backend matches this host', $out);
    expect(substr($out, -1))->toBe("\n");
});

test('config ends with newline for envoy validate', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
    ]);

    $out = app(EnvoyEdgeConfigBuilder::class)->build(
        new Collection([$site->fresh()]),
        80,
        fn () => 25001,
    );

    expect(substr($out, -1))->toBe("\n");
    $this->assertStringContainsString('cluster: cluster_', $out);
    $this->assertStringContainsString('name: dply_unmatched', $out);
});

test('admin listener on localhost 9901', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    $this->assertStringContainsString('address: 127.0.0.1', $out);
    $this->assertStringContainsString('port_value: 9901', $out);
});

test('merges custom clusters and operator settings', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(
        new Collection([]),
        80,
        fn ($s) => 20000,
        customClusters: [[
            'name' => 'api_pool',
            'connect_timeout' => '2s',
            'lb_policy' => 'LEAST_REQUEST',
            'endpoints' => ['127.0.0.1:9090'],
        ]],
        operatorSettings: ['admin_port' => '9902', 'stat_prefix' => 'edge_ingress'],
    );

    $this->assertStringContainsString('name: api_pool', $out);
    $this->assertStringContainsString('port_value: 9090', $out);
    $this->assertStringContainsString('lb_policy: LEAST_REQUEST', $out);
    $this->assertStringContainsString('port_value: 9902', $out);
    $this->assertStringContainsString('stat_prefix: edge_ingress', $out);
});

test('merges custom virtual hosts before catch-all', function () {
    $out = app(EnvoyEdgeConfigBuilder::class)->build(
        new Collection([]),
        80,
        fn ($s) => 20000,
        customVirtualHosts: [[
            'name' => 'api',
            'domains' => ['api.example.com'],
            'cluster' => 'api_pool',
        ]],
    );

    $this->assertStringContainsString('name: vhost_custom_api', $out);
    $this->assertStringContainsString('"api.example.com"', $out);
    $this->assertStringContainsString('cluster: api_pool', $out);

    $customPos = strpos($out, 'vhost_custom_api');
    $unmatchedPos = strpos($out, 'dply_unmatched');
    expect($customPos)->toBeLessThan($unmatchedPos);
});

test('merges custom listeners in shared and cluster modes', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
    ]);

    $out = app(EnvoyEdgeConfigBuilder::class)->build(
        new Collection([$site->fresh()]),
        80,
        fn () => 25001,
        customListeners: [
            [
                'name' => 'alt_shared',
                'address' => '0.0.0.0',
                'port' => 8080,
                'mode' => 'shared',
                'default_cluster' => '',
            ],
            [
                'name' => 'metrics',
                'address' => '127.0.0.1',
                'port' => 9090,
                'mode' => 'cluster',
                'default_cluster' => 'metrics_pool',
            ],
        ],
    );

    $this->assertStringContainsString('name: alt_shared', $out);
    $this->assertStringContainsString('port_value: 8080', $out);
    $this->assertStringContainsString('"app.example.com:8080"', $out);
    $this->assertStringContainsString('name: metrics', $out);
    $this->assertStringContainsString('address: 127.0.0.1', $out);
    $this->assertStringContainsString('port_value: 9090', $out);
    $this->assertStringContainsString('cluster: metrics_pool', $out);
});
