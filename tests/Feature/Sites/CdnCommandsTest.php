<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CdnCommandsTest;

use App\Jobs\ApplySiteCdnJob;
use App\Jobs\PurgeSiteCdnJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: ProviderCredential}
 */
function seedCdnCommandSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'slug' => 'cdn-test-site',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
        'www_redirect' => false,
    ]);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'name' => 'CF',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return [$site->fresh(), $credential];
}

test('cdn-enable writes meta, audits, and dispatches', function () {
    Bus::fake();
    [$site, $credential] = seedCdnCommandSite();

    $this->artisan('dply:site:cdn-enable', ['site' => $site->slug])
        ->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->meta['cdn']['enabled'] ?? null)->toBeTrue();
    expect($fresh->meta['cdn']['provider'] ?? null)->toBe('cloudflare');
    expect($fresh->meta['cdn']['credential_id'] ?? null)->toBe($credential->id);
    expect($fresh->meta['cdn']['hostname'] ?? null)->toBe('app.example.com');
    expect($fresh->meta['cdn']['zone_name'] ?? null)->toBe('example.com');
    expect($fresh->meta['cdn']['origin_ip'] ?? null)->toBe('203.0.113.10');

    Bus::assertDispatched(ApplySiteCdnJob::class);

    $audit = SiteAuditEvent::query()->where('site_id', $site->id)->where('action', 'site_cdn_enabled')->first();
    expect($audit)->not->toBeNull();
    expect($audit->transport)->toBe('cli');
});

test('cdn-enable fails with no cloudflare credential', function () {
    Bus::fake();
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id, 'organization_id' => $org->id, 'ip_address' => '203.0.113.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id, 'slug' => 'no-cred-site',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id, 'hostname' => 'x.example.com', 'is_primary' => true, 'www_redirect' => false,
    ]);

    $this->artisan('dply:site:cdn-enable', ['site' => $site->slug])
        ->expectsOutputToContain('No Cloudflare credential available')
        ->assertFailed();

    Bus::assertNotDispatched(ApplySiteCdnJob::class);
});

test('cdn-enable rejects invalid preset', function () {
    [$site] = seedCdnCommandSite();

    $this->artisan('dply:site:cdn-enable', ['site' => $site->slug, '--preset' => 'banana'])
        ->assertFailed();
});

test('cdn-disable flips enabled flag and dispatches', function () {
    Bus::fake();
    [$site, $credential] = seedCdnCommandSite();
    $site->meta = ['cdn' => [
        'enabled' => true, 'provider' => 'cloudflare', 'credential_id' => $credential->id,
        'zone_name' => 'example.com', 'hostname' => 'app.example.com', 'origin_ip' => '203.0.113.10',
        'cache_preset' => 'standard', 'zone_id' => 'zone-1', 'record_id' => 'rec-1',
    ]];
    $site->save();

    $this->artisan('dply:site:cdn-disable', ['site' => $site->slug])->assertSuccessful();

    expect($site->fresh()->meta['cdn']['enabled'])->toBeFalse();
    Bus::assertDispatched(ApplySiteCdnJob::class);
});

test('cdn-disable is a no-op when no cdn config', function () {
    Bus::fake();
    [$site] = seedCdnCommandSite();

    $this->artisan('dply:site:cdn-disable', ['site' => $site->slug])->assertSuccessful();
    Bus::assertNotDispatched(ApplySiteCdnJob::class);
});

test('cdn-purge dispatches when enabled', function () {
    Bus::fake();
    [$site, $credential] = seedCdnCommandSite();
    $site->meta = ['cdn' => [
        'enabled' => true, 'provider' => 'cloudflare', 'credential_id' => $credential->id,
        'hostname' => 'app.example.com', 'zone_id' => 'zone-1',
    ]];
    $site->save();

    $this->artisan('dply:site:cdn-purge', ['site' => $site->slug])->assertSuccessful();
    Bus::assertDispatched(PurgeSiteCdnJob::class);

    $audit = SiteAuditEvent::query()->where('site_id', $site->id)->where('action', 'site_cdn_purged')->first();
    expect($audit)->not->toBeNull();
});

test('cdn-purge no-ops when disabled', function () {
    Bus::fake();
    [$site] = seedCdnCommandSite();

    $this->artisan('dply:site:cdn-purge', ['site' => $site->slug])->assertSuccessful();
    Bus::assertNotDispatched(PurgeSiteCdnJob::class);
});
