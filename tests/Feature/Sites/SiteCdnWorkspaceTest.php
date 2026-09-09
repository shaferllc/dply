<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteCdnWorkspaceTest;

use App\Enums\ServerProvider;
use App\Jobs\ApplySiteCdnJob;
use App\Livewire\Sites\Cdn;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * GA coverage for the CDN / Edge workspace — the coming-soon path is covered in
 * SiteCdnPreviewTest. These assert what an operator gets once
 * `workspace.site_cdn` is on: the provider-gated empty state, and the real
 * config round-trip into `meta['cdn']` + the apply job.
 */
beforeEach(function (): void {
    Feature::define('workspace.site_cdn', fn (): bool => true);
    Feature::define('workspace.site_cdn_preview', fn (): bool => false);
    Feature::flushCache();
});

test('cdn tab has no soon badge once the feature is on', function (): void {
    [$user, $server, $site] = siteCdnWorkspaceFixtures();

    $cdn = collect(SiteSettingsSidebar::items($site->fresh(), $server))
        ->firstWhere('id', 'cdn');

    expect($cdn)->not->toBeNull();
    expect($cdn['preview'] ?? false)->toBeFalsy();
});

test('cdn route renders the connect-a-provider state not the teaser', function (): void {
    [$user, $server, $site] = siteCdnWorkspaceFixtures();

    // No CDN-capable credential on the org yet — the real component's gated
    // empty state, which is distinct from the coming-soon panel.
    // Assert on the teaser's own heading, not "Coming soon" — the workspace
    // sidebar still carries Soon badges for other previewed surfaces.
    $this->actingAs($user)
        ->get(route('sites.cdn', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Connect a CDN provider'))
        ->assertDontSee(__('Edge cache & proxy'));
});

test('saving an edge config persists cdn meta and queues the apply job', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCdnWorkspaceFixtures();
    $credential = cloudflareCredentialFor($site);

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('provider', 'cloudflare')
        ->set('credentialId', $credential->id)
        ->set('zoneName', 'Example.com')
        ->set('hostname', 'WWW.Example.com')
        ->set('originIp', '203.0.113.10')
        ->set('cachePreset', 'aggressive')
        ->call('save')
        ->assertHasNoErrors();

    $cfg = $site->fresh()->cdnConfig();

    expect($cfg['enabled'])->toBeTrue();
    expect($cfg['provider'])->toBe('cloudflare');
    expect($cfg['credential_id'])->toBe($credential->id);
    // Zone + hostname are normalised to lowercase before they reach Cloudflare.
    expect($cfg['zone_name'])->toBe('example.com');
    expect($cfg['hostname'])->toBe('www.example.com');
    expect($cfg['cache_preset'])->toBe('aggressive');

    Queue::assertPushed(ApplySiteCdnJob::class);
});

test('save rejects an invalid origin ip', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCdnWorkspaceFixtures();
    $credential = cloudflareCredentialFor($site);

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('provider', 'cloudflare')
        ->set('credentialId', $credential->id)
        ->set('zoneName', 'example.com')
        ->set('hostname', 'www.example.com')
        ->set('originIp', 'not-an-ip')
        ->set('cachePreset', 'standard')
        ->call('save')
        ->assertHasErrors('originIp');

    Queue::assertNotPushed(ApplySiteCdnJob::class);
});

test('a credential from another organization is refused', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCdnWorkspaceFixtures();

    $otherOrg = Organization::factory()->create();
    $foreign = ProviderCredential::factory()->create([
        'organization_id' => $otherOrg->id,
        'provider' => ServerProvider::Cloudflare->value,
    ]);

    Livewire::actingAs($user)
        ->test(Cdn::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->set('provider', 'cloudflare')
        ->set('credentialId', $foreign->id)
        ->set('zoneName', 'example.com')
        ->set('hostname', 'www.example.com')
        ->set('originIp', '203.0.113.10')
        ->set('cachePreset', 'standard')
        ->call('save');

    expect($site->fresh()->cdnConfig())->toBe([]);
    Queue::assertNotPushed(ApplySiteCdnJob::class);
});

function cloudflareCredentialFor(Site $site): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'organization_id' => $site->organization_id,
        'provider' => ServerProvider::Cloudflare->value,
    ]);
}

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function siteCdnWorkspaceFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
