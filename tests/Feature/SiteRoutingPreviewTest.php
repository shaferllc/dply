<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRoutingPreviewTest;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('save preview settings round trips', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'preview')
        ->set('preview_primary_hostname', 'preview.example.dply.cc')
        ->set('preview_label', 'Managed preview')
        ->set('preview_auto_ssl', true)
        ->set('preview_https_redirect', false)
        ->call('savePreviewSettings')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_preview_domains', [
        'site_id' => $site->id,
        'hostname' => 'preview.example.dply.cc',
        'is_primary' => true,
        'auto_ssl' => true,
        'https_redirect' => false,
    ]);
});
test('confirm remove preview domain', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $preview = SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'old-preview.example.com',
        'label' => 'Old',
        'dns_status' => 'pending',
        'ssl_status' => 'pending',
        'is_primary' => false,
        'auto_ssl' => true,
        'https_redirect' => true,
        'managed_by_dply' => true,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'preview')
        ->call('confirmRemovePreviewDomain', (string) $preview->id)
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal');

    expect($preview->fresh())->toBeNull();
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeUserSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
