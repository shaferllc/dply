<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRoutingDomainsTest;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('add domain with comment and auto applies', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->set('new_domain_hostname', 'foo.example.com')
        ->set('new_domain_comment', 'EU CDN')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertSet('new_domain_hostname', '')
        ->assertSet('new_domain_comment', '');

    $this->assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'foo.example.com',
        'comment' => 'EU CDN',
    ]);
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
});
test('inline edit updates hostname and comment', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $domain = SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'old.example.com',
        'is_primary' => false,
        'www_redirect' => false,
        'comment' => 'old comment',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->call('editDomain', $domain->id)
        ->assertSet('editing_domain_id', (string) $domain->id)
        ->assertSet('editing_domain_hostname', 'old.example.com')
        ->assertSet('editing_domain_comment', 'old comment')
        ->set('editing_domain_hostname', 'new.example.com')
        ->set('editing_domain_comment', 'new comment')
        ->call('saveEditedDomain')
        ->assertHasNoErrors()
        ->assertSet('editing_domain_id', null);

    expect($domain->fresh()->hostname)->toBe('new.example.com');
    expect($domain->fresh()->comment)->toBe('new comment');
});
test('confirm remove domain routes through modal', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $domain = SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'gone.example.com',
        'is_primary' => false,
        'www_redirect' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->call('confirmRemoveDomain', $domain->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'removeDomain');

    // Domain still present until the modal's Confirm fires.
    expect($domain->fresh())->not->toBeNull();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->call('confirmRemoveDomain', $domain->id)
        ->call('confirmActionModal');

    expect($domain->fresh())->toBeNull();
});
test('bulk import creates new domains and skips existing', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'existing.example.com',
        'is_primary' => false,
        'www_redirect' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->set('bulk_domain_input', "one.example.com\nexisting.example.com\ntwo.example.com\n")
        ->call('bulkImportDomains')
        ->assertHasNoErrors()
        ->assertSet('bulk_domain_input', '');

    $this->assertDatabaseHas('site_domains', ['hostname' => 'one.example.com']);
    $this->assertDatabaseHas('site_domains', ['hostname' => 'two.example.com']);

    // existing.example.com only present once (skip on collision).
    expect(SiteDomain::query()->where('hostname', 'existing.example.com')->count())->toBe(1);
});
test('bulk import aborts on invalid hostname', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'domains')
        ->set('bulk_domain_input', "good.example.com\nnot a hostname\n")
        ->call('bulkImportDomains')
        ->assertHasErrors(['bulk_domain_input']);

    // Nothing imported — abort-on-error is the convention from env bulk import.
    $this->assertDatabaseMissing('site_domains', ['hostname' => 'good.example.com']);
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

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
