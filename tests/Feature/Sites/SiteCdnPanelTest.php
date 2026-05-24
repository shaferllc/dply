<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteCdnPanelTest;

use App\Jobs\ApplySiteCdnJob;
use App\Jobs\PurgeSiteCdnJob;
use App\Livewire\Sites\Cdn;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site, 3: ProviderCredential}
 */
function setUpCdnSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'is_primary' => true,
        'www_redirect' => false,
    ]);
    $site = $site->fresh();
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'name' => 'CF prod',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return [$user, $server, $site, $credential];
}

test('empty state shows when no CDN credential exists', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->assertSee('Connect a CDN provider');
});

test('hydrates defaults from primary domain and server ip', function () {
    [$user, $server, $site] = setUpCdnSite();

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->assertSet('hostname', 'app.example.com')
        ->assertSet('zoneName', 'example.com')
        ->assertSet('originIp', '203.0.113.10');
});

test('save persists meta and dispatches apply job', function () {
    Bus::fake();
    [$user, $server, $site, $credential] = setUpCdnSite();

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('credentialId', $credential->id)
        ->set('cachePreset', 'aggressive')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->meta['cdn']['enabled'] ?? null)->toBeTrue();
    expect($fresh->meta['cdn']['provider'] ?? null)->toBe('cloudflare');
    expect($fresh->meta['cdn']['cache_preset'] ?? null)->toBe('aggressive');
    expect($fresh->meta['cdn']['hostname'] ?? null)->toBe('app.example.com');
    expect($fresh->meta['cdn']['origin_ip'] ?? null)->toBe('203.0.113.10');

    Bus::assertDispatched(ApplySiteCdnJob::class, fn ($job) => $job->siteId === $site->id);

    $audit = SiteAuditEvent::query()->where('site_id', $site->id)->where('action', 'site_cdn_enabled')->first();
    expect($audit)->not->toBeNull();
    expect($audit->transport)->toBe('web');
    expect($audit->user_id)->toBe($user->id);
});

test('save validates ip address', function () {
    [$user, $server, $site, $credential] = setUpCdnSite();

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('credentialId', $credential->id)
        ->set('originIp', 'not-an-ip')
        ->call('save')
        ->assertHasErrors(['originIp' => 'ip']);
});

test('purge dispatches purge job only when enabled', function () {
    Bus::fake();
    [$user, $server, $site, $credential] = setUpCdnSite();

    // Pre-seed an enabled CDN config so purge() proceeds.
    $site->meta = array_merge($site->meta ?? [], [
        'cdn' => [
            'enabled' => true,
            'provider' => 'cloudflare',
            'credential_id' => $credential->id,
            'zone_name' => 'example.com',
            'hostname' => 'app.example.com',
            'origin_ip' => '203.0.113.10',
            'zone_id' => 'zone-1',
            'cache_preset' => 'standard',
        ],
    ]);
    $site->save();

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->call('purge');

    Bus::assertDispatched(PurgeSiteCdnJob::class, fn ($job) => $job->siteId === $site->id);
});

test('purge refuses when edge disabled', function () {
    Bus::fake();
    [$user, $server, $site] = setUpCdnSite();

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->call('purge');

    Bus::assertNotDispatched(PurgeSiteCdnJob::class);
});

test('save rejects credential from another organization', function () {
    [$user, $server, $site] = setUpCdnSite();
    $otherOrg = Organization::factory()->create();
    $foreign = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $otherOrg->id,
        'provider' => 'cloudflare',
        'name' => 'foreign',
        'credentials' => ['api_token' => 'tok'],
    ]);

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('credentialId', $foreign->id)
        ->call('save');

    $fresh = $site->fresh();
    expect($fresh->meta['cdn'] ?? null)->toBeNull();
});
