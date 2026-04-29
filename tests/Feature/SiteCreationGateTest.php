<?php

namespace Tests\Feature;

use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPhpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteCreationGateTest extends TestCase
{
    use RefreshDatabase;

    private function actingInOrg(User $user, Organization $org): void
    {
        $this->actingAs($user);
        session(['current_organization_id' => $org->id]);
    }

    public function test_site_create_forbidden_when_server_has_no_organization(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => null,
        ]);

        $this->actingInOrg($user, $org);

        $this->get(route('sites.create', $server))->assertForbidden();
    }

    public function test_deployer_cannot_open_site_create_form(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingInOrg($user, $org);

        $this->get(route('sites.create', $server))->assertForbidden();
    }

    public function test_site_create_forbidden_when_org_at_site_limit(): void
    {
        Config::set('subscription.limits.sites_free', 1);

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

        $this->actingInOrg($user, $org);

        $this->get(route('sites.create', $server))->assertForbidden();
    }

    public function test_owner_can_delete_site(): void
    {
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

        $this->actingInOrg($user, $org);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('deleteSite')
            ->assertRedirect(route('servers.show', $server, false));

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_member_cannot_delete_site(): void
    {
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

        $this->actingInOrg($member, $org);

        Livewire::actingAs($member)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('deleteSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_site_show_displays_php_summary_with_current_version_and_installed_versions(): void
    {
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
            'meta' => [
                'php_runtime' => [
                    'memory_limit' => '512M',
                    'upload_max_filesize' => '64M',
                    'max_execution_time' => '120',
                ],
            ],
        ]);

        $this->actingInOrg($user, $org);

        $response = $this->get(route('sites.show', [$server, $site]));

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
    }

    public function test_site_show_flags_php_version_mismatch_and_links_to_server_php_workspace(): void
    {
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

        $this->actingInOrg($user, $org);

        $response = $this->get(route('sites.show', [$server, $site]));

        $response->assertOk()
            ->assertSee('PHP version mismatch')
            ->assertSee('This site references PHP 8.2, but that version is not currently installed on this server.')
            ->assertSee(route('servers.php', $server, false), escape: false);
    }

    public function test_site_show_can_save_php_version_and_runtime_settings_and_reject_non_installed_versions(): void
    {
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

        $this->actingInOrg($user, $org);

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

        $this->assertSame('8.4', $site->php_version);
        $this->assertSame([
            'memory_limit' => '768M',
            'upload_max_filesize' => '128M',
            'max_execution_time' => '300',
        ], $site->meta['php_runtime'] ?? null);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server->fresh(), 'site' => $site->fresh()])
            ->set('php_version', '8.2')
            ->call('savePhpSettings')
            ->assertHasErrors(['php_version']);
    }
}
