<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\OpenLiteSpeedHttpdConfigBuilderTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
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
test('emits listener block bound to provided port', function () {
    $sites = new Collection([]);

    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 8080);

    $this->assertStringContainsString('listener Default {', $out);
    expect($out)->toMatch('/address\s+\*:8080\b/');
    $this->assertDoesNotMatchRegularExpression('/address\s+\*:80\b/', $out);
});
test('cutover listener bound to 80', function () {
    $sites = new Collection([]);

    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 80);

    expect($out)->toMatch('/address\s+\*:80\b/');
    $this->assertDoesNotMatchRegularExpression('/address\s+\*:8080\b/', $out);
});
test('production config adds https listener when site expects tls', function () {
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
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview.example.com',
        'is_primary' => true,
    ]);

    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([$site->fresh()]), 80);

    $this->assertStringContainsString('listener DefaultSsl {', $out);
    $this->assertStringContainsString('address                 *:443', $out);
    $this->assertStringContainsString('keyFile                 /etc/letsencrypt/live/preview.example.com/privkey.pem', $out);
    $basename = $site->webserverConfigBasename();
    $this->assertStringContainsString("map                     {$basename} preview.example.com", $out);
    $this->assertStringContainsString("virtualhost {$basename} {", $out);
});
test('staging config on 8080 omits https listener', function () {
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
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'preview.example.com',
        'is_primary' => true,
    ]);

    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([$site->fresh()]), 8080);

    $this->assertStringNotContainsString('listener DefaultSsl {', $out);
});
test('emits vhtemplate block per site', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);

    $site1 = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'first-site',
        'runtime' => 'php',
    ]);
    $site2 = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'second-site',
        'runtime' => 'static',
    ]);

    SiteDomain::query()->create([
        'site_id' => $site1->id,
        'hostname' => 'first.example.com',
        'is_primary' => true,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site2->id,
        'hostname' => 'second.example.com',
        'is_primary' => true,
    ]);

    $sites = new Collection([$site1->fresh(), $site2->fresh()]);
    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, 8080);

    $basename1 = $site1->webserverConfigBasename();
    $basename2 = $site2->webserverConfigBasename();

    $this->assertStringContainsString("virtualhost {$basename1} {", $out);
    $this->assertStringContainsString("virtualhost {$basename2} {", $out);
    $this->assertStringContainsString('configFile              /usr/local/lsws/conf/vhosts/'.$basename1.'/vhconf.conf', $out);
    $this->assertStringContainsString('configFile              /usr/local/lsws/conf/vhosts/'.$basename2.'/vhconf.conf', $out);
    $this->assertStringContainsString('map                     '.$basename1.' first.example.com', $out);
    $this->assertStringContainsString('map                     '.$basename2.' second.example.com', $out);

    // vhRoot pinned to repo path so $VH_ROOT in vhconf resolves to a
    // directory dply manages, not the default conf/vhosts/<name>.
    $this->assertStringContainsString('vhRoot                  '.rtrim($site1->effectiveRepositoryPath(), '/'), $out);
});
test('emits server-level lsphp extprocessor for php sites', function () {
    $user = makeUserWithOrg();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'type' => SiteType::Php,
    ]);

    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([$site->fresh()]), 80);

    $this->assertStringContainsString('extprocessor lsphp83 {', $out);
    $this->assertStringContainsString('path                    lsphp83/bin/lsphp', $out);
    $this->assertStringContainsString('setUIDMode              0', $out);
});
test('marks output as dply managed', function () {
    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([]), 80);

    $this->assertStringContainsString('Managed by Dply', $out);
    $this->assertStringContainsString('do NOT hand-edit', $out);
});
test('includes runcloud-style performance defaults', function () {
    $out = app(OpenLiteSpeedHttpdConfigBuilder::class)->build(new Collection([]), 80);

    $this->assertStringContainsString('module cache {', $out);
    $this->assertStringContainsString('enableCache', $out);
    $this->assertStringContainsString('module modgzip {', $out);
    $this->assertStringContainsString('expires {', $out);
    $this->assertStringContainsString('enableExpires', $out);
    $this->assertStringContainsString('enableGzipCompress', $out);
});
