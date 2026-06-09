<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRoutingAliasesTest;

use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomainAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('add alias with comment', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'aliases')
        ->set('new_alias_hostname', 'alt.example.com')
        ->set('new_alias_label', 'Marketing')
        ->set('new_alias_comment', 'EU CDN alias')
        ->call('addAlias')
        ->assertHasNoErrors()
        ->assertSet('new_alias_comment', '');

    $this->assertDatabaseHas('site_domain_aliases', [
        'site_id' => $site->id,
        'hostname' => 'alt.example.com',
        'comment' => 'EU CDN alias',
    ]);
});
test('inline edit alias', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $alias = SiteDomainAlias::query()->create([
        'site_id' => $site->id,
        'hostname' => 'old.example.com',
        'label' => 'Old',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'aliases')
        ->call('editAlias', $alias->id)
        ->assertSet('editing_alias_hostname', 'old.example.com')
        ->set('editing_alias_hostname', 'new.example.com')
        ->set('editing_alias_comment', 'updated')
        ->call('saveEditedAlias')
        ->assertHasNoErrors();

    expect($alias->fresh()->hostname)->toBe('new.example.com');
    expect($alias->fresh()->comment)->toBe('updated');
});
test('confirm remove alias', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();
    $alias = SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'gone.example.com']);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'aliases')
        ->call('confirmRemoveAlias', (string) $alias->id)
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal');

    expect($alias->fresh())->toBeNull();
});
test('bulk import aliases with label', function () {
    Bus::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
        ->set('routingTab', 'aliases')
        ->set('bulk_alias_input', "alt.example.com\nmkt.example.com,Marketing\n")
        ->call('bulkImportAliases')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_domain_aliases', ['hostname' => 'alt.example.com', 'label' => null]);
    $this->assertDatabaseHas('site_domain_aliases', ['hostname' => 'mkt.example.com', 'label' => 'Marketing']);
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
