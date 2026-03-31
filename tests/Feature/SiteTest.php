<?php

namespace Tests\Feature;

use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_site_page_shows_php_card_with_current_version_and_installed_versions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('PHP')
            ->assertSee('Current site version')
            ->assertSee('PHP 8.3')
            ->assertSee('Installed on this server')
            ->assertSee('PHP 8.4')
            ->assertSee('Memory limit')
            ->assertSee('Upload max filesize')
            ->assertSee('Max execution time')
            ->assertSee('OPcache')
            ->assertSee('Composer auth')
            ->assertSee('Extensions');
    }

    public function test_site_page_shows_php_mismatch_state_and_server_php_remediation_link(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.1',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('PHP version mismatch')
            ->assertSee('This site references PHP 8.1, but that version is not currently installed on this server.')
            ->assertSee(route('servers.php', $server, false), escape: false)
            ->assertDontSee('value="8.1"', escape: false);
    }

    public function test_site_php_selector_hides_unsupported_installed_versions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.5'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.4',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [$server, $site], false));

        $response->assertOk()
            ->assertSee('PHP 8.4')
            ->assertDontSee('value="8.5"', escape: false);
    }

    public function test_site_php_settings_can_be_saved_for_installed_versions_only(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.4',
                ],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'php_version' => '8.3',
            'meta' => [
                'php_runtime' => [
                    'memory_limit' => '256M',
                    'upload_max_filesize' => '64M',
                    'max_execution_time' => '60',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->set('php_version', '8.4')
            ->set('php_memory_limit', '512M')
            ->set('php_upload_max_filesize', '128M')
            ->set('php_max_execution_time', '120')
            ->call('savePhpSettings')
            ->assertHasNoErrors()
            ->assertSet('flash_success', 'PHP settings saved.');

        $site->refresh();

        $this->assertSame('8.4', $site->php_version);
        $this->assertSame('512M', data_get($site->meta, 'php_runtime.memory_limit'));
        $this->assertSame('128M', data_get($site->meta, 'php_runtime.upload_max_filesize'));
        $this->assertSame('120', (string) data_get($site->meta, 'php_runtime.max_execution_time'));

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site->fresh()])
            ->set('php_version', '8.1')
            ->call('savePhpSettings')
            ->assertHasErrors(['php_version']);
    }

    public function test_php_site_creation_prefills_the_valid_server_new_site_default(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4', '8.3'],
                    'detected_default_version' => '8.3',
                ],
                'default_php_version' => '8.3',
                'php_new_site_default_version' => '8.4',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.type', 'php')
            ->assertSet('form.php_version', '8.4');
    }

    public function test_php_site_creation_requires_explicit_selection_when_saved_default_is_not_installed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
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
                'php_new_site_default_version' => '8.1',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.type', 'php')
            ->assertSet('form.php_version', '')
            ->set('form.name', 'Example App')
            ->set('form.primary_hostname', 'app.example.com')
            ->set('form.document_root', '/var/www/app/public')
            ->set('form.repository_path', '/var/www/app')
            ->call('store')
            ->assertHasErrors(['form.php_version']);
    }
}
