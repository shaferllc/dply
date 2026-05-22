<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\HAProxyEdgeConfigBuilderTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Servers\HAProxyEdgeConfigBuilder;
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
test('frontend binds to provided port', function () {
    $out = app(HAProxyEdgeConfigBuilder::class)->build(new Collection([]), 8080, fn ($s) => 20000);

    expect($out)->toMatch('/bind \*:8080\b/');
    $this->assertDoesNotMatchRegularExpression('/bind \*:80\b/', $out);
});
test('cutover frontend binds to 80', function () {
    $out = app(HAProxyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    expect($out)->toMatch('/bind \*:80\b/');
    $this->assertDoesNotMatchRegularExpression('/bind \*:8080\b/', $out);
});
test('emits acl and backend per site', function () {
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

    $out = app(HAProxyEdgeConfigBuilder::class)->build(
        $sites,
        80,
        fn (Site $s): int => $portMap[$s->id],
    );

    // Identifiers are derived from each site's webserverConfigBasename()
    // with the same `[^A-Za-z0-9_] → _` sanitization the builder applies.
    // Site factory generates basenames like `dply-01k...-<random>`, so
    // we compute them dynamically rather than hardcoding short labels.
    $id1 = preg_replace('/[^A-Za-z0-9_]/', '_', $site1->webserverConfigBasename());
    $id2 = preg_replace('/[^A-Za-z0-9_]/', '_', $site2->webserverConfigBasename());

    // One ACL per hostname matching by Host header.
    $this->assertStringContainsString('hdr(host) -i alpha.example.com', $out);
    $this->assertStringContainsString('hdr(host) -i beta.example.com', $out);

    // One use_backend per site keyed by the ACL name.
    expect($out)->toMatch('/use_backend bk_'.preg_quote($id1, '/').' if host_'.preg_quote($id1, '/').'/');
    expect($out)->toMatch('/use_backend bk_'.preg_quote($id2, '/').' if host_'.preg_quote($id2, '/').'/');

    // Backend block per site pointing at the supplied Caddy upstream port.
    $this->assertStringContainsString('backend bk_'.$id1, $out);
    $this->assertStringContainsString('server caddy 127.0.0.1:25001', $out);
    $this->assertStringContainsString('backend bk_'.$id2, $out);
    $this->assertStringContainsString('server caddy 127.0.0.1:25002', $out);
});
test('marks output as dply managed', function () {
    $out = app(HAProxyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    $this->assertStringContainsString('Managed by Dply', $out);
    $this->assertStringContainsString('do NOT hand-edit', $out);
});
test('emits 503 fallback for unmatched hosts', function () {
    // A frontend with no ACL match should return a clean 503 so
    // misconfigured DNS / typos are obvious in the response body rather
    // than HAProxy's default blank error page.
    $out = app(HAProxyEdgeConfigBuilder::class)->build(new Collection([]), 80, fn ($s) => 20000);

    $this->assertStringContainsString('http-request return status 503', $out);
    $this->assertStringContainsString('no backend matches this host', $out);
});
