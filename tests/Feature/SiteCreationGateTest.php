<?php


namespace Tests\Feature\SiteCreationGateTest;
use Mockery;

use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPhpManager;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function actingInOrg(User $user, Organization $org): void
{
    $this->actingAs($user);
    session(['current_organization_id' => $org->id]);
}

test('site create forbidden when server has no organization', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => null,
    ]);

    actingInOrg($user, $org);

    $this->get(route('sites.create', $server))->assertForbidden();
});

test('deployer cannot open site create form', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    actingInOrg($user, $org);

    $this->get(route('sites.create', $server))->assertForbidden();
});

test('site create always allowed now that site caps are retired', function () {
    // Under the Standard pricing model sites are uncapped. The previous
    // "forbidden when at trial site limit" behavior is gone — billing is
    // per-server and trial-state gating handles abuse.
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    actingInOrg($user, $org);

    $this->get(route('sites.create', $server))->assertOk();
});

test('owner can delete site', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    actingInOrg($user, $org);

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->call('deleteSite')
        ->assertRedirect(route('servers.show', $server, false));

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
});

test('member cannot delete site', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'member']);
    $server = Server::factory()->ready()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    actingInOrg($member, $org);

    Livewire::actingAs($member)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->call('deleteSite')
        ->assertForbidden();

    $this->assertDatabaseHas('sites', ['id' => $site->id]);
});

test('site show displays php summary with current version and installed versions', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.3',
        ],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'php_version' => '8.3',
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [
            'php_runtime' => [
                'memory_limit' => '512M',
                'upload_max_filesize' => '64M',
                'max_execution_time' => '120',
            ],
        ],
    ]);

    actingInOrg($user, $org);

    $response = $this->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime-php']));

    $response->assertOk()
        ->assertSee('PHP')
        ->assertSee('Current site version')
        ->assertSee('PHP 8.3')
        ->assertSee('Installed on this server')
        ->assertSee('PHP 8.4')
        ->assertSee('Memory limit')
        ->assertSee('512M')
        ->assertSee('Upload max filesize')
        ->assertSee('64M')
        ->assertSee('Max execution time')
        ->assertSee('120')
        ->assertSee('OPcache')
        ->assertSee('Composer auth')
        ->assertSee('Extensions');
});

test('site show flags php version mismatch and links to server php workspace', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.3',
        ],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'php_version' => '8.2',
    ]);

    actingInOrg($user, $org);

    $response = $this->get(route('sites.show', [$server, $site]));

    $response->assertOk()
        ->assertSee('PHP version mismatch')
        ->assertSee('This site references PHP 8.2, but that version is not currently installed on this server.')
        ->assertSee(route('servers.php', $server, false), escape: false);
});

test('site show can save php version and runtime settings and reject non installed versions', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.3',
        ],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'php_version' => '8.3',
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('sitePhpData')
        ->atLeast()->once()
        ->andReturnUsing(fn (Server $resolvedServer, Site $resolvedSite) => [
            'current_version' => $resolvedSite->php_version,
            'current_version_label' => $resolvedSite->php_version ? 'PHP '.$resolvedSite->php_version : null,
            'installed_versions' => [
                ['id' => '8.4', 'label' => 'PHP 8.4', 'is_supported' => true],
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true],
            ],
            'selected_version_installed' => in_array($resolvedSite->php_version, ['8.4', '8.3'], true),
            'mismatch_version' => in_array($resolvedSite->php_version, ['8.4', '8.3'], true) ? null : $resolvedSite->php_version,
            'server_php_workspace_url' => route('servers.php', $resolvedServer, false),
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    actingInOrg($user, $org);

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->set('php_version', '8.4')
        ->set('php_memory_limit', '768M')
        ->set('php_upload_max_filesize', '128M')
        ->set('php_max_execution_time', '300')
        ->call('savePhpSettings')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'PHP settings saved.', type: 'success');

    $site->refresh();

    expect($site->php_version)->toBe('8.4');
    expect($site->meta['php_runtime'] ?? null)->toBe([
        'memory_limit' => '768M',
        'upload_max_filesize' => '128M',
        'max_execution_time' => '300',
    ]);

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server->fresh(), 'site' => $site->fresh()])
        ->set('php_version', '8.2')
        ->call('savePhpSettings')
        ->assertHasErrors(['php_version']);
});