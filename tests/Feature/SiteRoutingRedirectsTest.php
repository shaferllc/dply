<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRoutingRedirectsTest;

use App\Enums\SiteRedirectKind;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add http redirect with comment', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'redirects')
        ->set('new_redirect_kind', 'http')
        ->set('new_redirect_from', '/old')
        ->set('new_redirect_to', 'https://example.com/new')
        ->set('new_redirect_code', 302)
        ->set('new_redirect_comment', 'Mailchimp legacy URL')
        ->call('addRedirectRule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_redirects', [
        'site_id' => $site->id,
        'from_path' => '/old',
        'to_url' => 'https://example.com/new',
        'status_code' => 302,
        'comment' => 'Mailchimp legacy URL',
    ]);
});
test('inline edit redirect changes kind and status', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $redirect = SiteRedirect::query()->create([
        'site_id' => $site->id,
        'kind' => SiteRedirectKind::Http,
        'from_path' => '/legacy',
        'to_url' => 'https://example.com',
        'status_code' => 301,
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'redirects')
        ->call('editRedirect', $redirect->id)
        ->assertSet('editing_redirect_id', (string) $redirect->id)
        ->assertSet('editing_redirect_code', 301)
        ->set('editing_redirect_code', 302)
        ->set('editing_redirect_comment', 'updated')
        ->call('saveEditedRedirect')
        ->assertHasNoErrors();

    $fresh = $redirect->fresh();
    expect((int) $fresh->status_code)->toBe(302);
    expect($fresh->comment)->toBe('updated');
});
test('confirm remove redirect', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $redirect = SiteRedirect::query()->create([
        'site_id' => $site->id,
        'kind' => SiteRedirectKind::Http,
        'from_path' => '/x',
        'to_url' => '/y',
        'status_code' => 301,
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'redirects')
        ->call('confirmRemoveRedirect', $redirect->id)
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal');

    expect($redirect->fresh())->toBeNull();
});
test('bulk import redirects csv', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'redirects')
        ->set('bulk_redirect_input', "/a,/b\n/c,https://x.com,302\n")
        ->call('bulkImportRedirects')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_redirects', ['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);
    $this->assertDatabaseHas('site_redirects', ['from_path' => '/c', 'to_url' => 'https://x.com', 'status_code' => 302]);
});
test('bulk import aborts on invalid status code', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'redirects')
        ->set('bulk_redirect_input', "/a,/b,999\n")
        ->call('bulkImportRedirects')
        ->assertHasErrors(['bulk_redirect_input']);

    $this->assertDatabaseMissing('site_redirects', ['from_path' => '/a']);
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
