<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\LiveState\OpenRestyLiveStateProbeTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\LiveState\OpenRestyLiveStateProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteShell;
use Tests\Support\FakeSshConnectionFactory;

uses(RefreshDatabase::class);

test('openresty live state probe parses flattened config sections', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['edge_proxy' => 'openresty'],
    ]);

    $factory = app(FakeSshConnectionFactory::class);
    $factory->shell = new FakeRemoteShell([
        'openresty -V' => "nginx version: openresty/1.25.3.1\n",
        'openresty -T' => <<<'CONF'
upstream bk_site { server 127.0.0.1:25001; }
server {
    listen 80;
    server_name app.example.com;
    location / { proxy_pass http://bk_site; }
}
CONF,
        'curl -fsS --max-time 3 http://127.0.0.1:9149/nginx_status' => "Active connections: 1\nReading: 0 Writing: 1 Waiting: 0\n",
    ]);

    $state = app(OpenRestyLiveStateProbe::class)->probe($server->fresh());

    expect($state->units['upstreams'])->not->toBeEmpty();
    expect($state->units['servers'])->not->toBeEmpty();
    expect($state->units['runtime'][0]['version'] ?? '')->toContain('openresty');
});

test('openresty live state probe returns standby when not active edge proxy', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['edge_proxy' => 'haproxy'],
    ]);

    $state = app(OpenRestyLiveStateProbe::class)->probe($server);

    expect(data_get($state->engineSpecific, 'standby'))->toBeTrue();
});
