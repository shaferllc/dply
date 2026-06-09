<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\OpenRestyEdgeConfigBuilderTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Servers\OpenRestyEdgeConfigBuilder;
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
    $out = app(OpenRestyEdgeConfigBuilder::class)->build(new Collection([]), 8080, fn ($s) => 20000);

    expect($out)->toMatch('/listen 8080 default_server;/');
});

test('emits upstream server block and catch-all', function () {
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

    $out = app(OpenRestyEdgeConfigBuilder::class)->build(
        new Collection([$site->fresh()]),
        80,
        fn () => 25001,
        customUpstreams: [['name' => 'api_pool', 'servers' => ['127.0.0.1:9090']]],
        customServers: [['name' => 'api', 'server_names' => ['api.example.com'], 'upstream' => 'api_pool']],
    );

    $this->assertStringContainsString('upstream bk_', $out);
    $this->assertStringContainsString('server_name app.example.com', $out);
    $this->assertStringContainsString('upstream api_pool', $out);
    $this->assertStringContainsString('proxy_pass http://api_pool', $out);
    $this->assertStringContainsString('return 503', $out);
    $this->assertStringContainsString('stub_status on', $out);
});

test('buildForServer merges meta-backed custom config', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => [
            'openresty_operator_settings' => ['worker_processes' => '2', 'status_port' => '9150'],
            'openresty_custom_upstreams' => [['name' => 'metrics', 'servers' => ['127.0.0.1:9100']]],
        ],
    ]);

    $out = app(OpenRestyEdgeConfigBuilder::class)->buildForServer(
        $server,
        new Collection([]),
        80,
        fn () => 20000,
    );

    $this->assertStringContainsString('worker_processes 2;', $out);
    $this->assertStringContainsString('listen 127.0.0.1:9150;', $out);
    $this->assertStringContainsString('upstream metrics', $out);
});
