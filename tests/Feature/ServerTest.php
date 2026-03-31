<?php

namespace Tests\Feature;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\Create as ServersCreate;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\WorkspaceLogs;
use App\Livewire\Servers\WorkspaceManage;
use App\Livewire\Servers\WorkspaceSettings;
use App\Livewire\Servers\WorkspaceSites;
use App\Models\LogViewerShare;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_servers_index_redirects_guest(): void
    {
        $response = $this->get(route('servers.index'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_servers_index_is_displayed_for_authenticated_user(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('Servers');
    }

    public function test_servers_index_lists_servers_in_current_organization(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'My Server',
        ]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('My Server');
    }

    public function test_servers_index_search_filters_by_name(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-alpha-unique-xyz',
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-beta-unique-xyz',
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'alpha-unique')
            ->assertSee('demo-alpha-unique-xyz')
            ->assertDontSee('demo-beta-unique-xyz');
    }

    public function test_servers_index_status_filter_limits_rows(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-ready-filter-xyz',
            'status' => Server::STATUS_READY,
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-error-filter-xyz',
            'status' => Server::STATUS_ERROR,
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('statusFilter', Server::STATUS_ERROR)
            ->assertSee('srv-error-filter-xyz')
            ->assertDontSee('srv-ready-filter-xyz');
    }

    public function test_servers_index_reset_filters_clears_state(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'anything')
            ->set('statusFilter', Server::STATUS_READY)
            ->set('sort', 'name')
            ->set('viewMode', 'grid')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSet('sort', 'created_at')
            ->assertSet('viewMode', 'list');
    }

    public function test_servers_index_destroy_accepts_string_ulid_and_deletes(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $id = (string) $server->getKey();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->call('openRemoveServerModal', $id)
            ->set('deleteConfirmName', $server->name)
            ->set('deletePhraseControl', 'DELETE')
            ->set('currentPassword', 'password')
            ->call('submitRemoveServer');

        $this->assertModelMissing($server);
    }

    public function test_servers_create_requires_organization(): void
    {
        $user = User::factory()->create();
        // No organization, no session

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertForbidden();
    }

    public function test_servers_create_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertSee('Create server');
    }

    public function test_servers_can_be_stored_as_custom(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'custom')
            ->set('form.name', 'Custom Box')
            ->set('form.ip_address', '192.168.1.1')
            ->set('form.ssh_port', '22')
            ->set('form.ssh_user', 'root')
            ->set('form.ssh_private_key', "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----")
            ->call('store')
            ->assertRedirect();

        $this->assertDatabaseHas('servers', [
            'name' => 'Custom Box',
            'organization_id' => $org->id,
            'provider' => 'custom',
            'status' => 'ready',
        ]);
    }

    public function test_servers_show_is_displayed_for_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test Server',
        ]);

        $this->actingAs($user)->get(route('servers.show', $server))->assertRedirect(route('servers.sites', $server));

        $response = $this->actingAs($user)->get(route('servers.sites', $server));
        $response->assertOk();
        $response->assertSee('Test Server');
    }

    public function test_server_show_logs_tab_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->assertSee('Log source')
            ->assertSee('Dply activity')
            ->assertSee(__('Options'))
            ->assertSee(__('Lines to tail'))
            ->assertSee(__('Lines visible'))
            ->assertSee(__('Clear display'))
            ->assertSee(__('Copy'))
            ->assertSee(__('Regex'))
            ->assertSee(__('Time range'));
    }

    public function test_log_viewer_share_link_can_be_created_and_viewed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', 'snapshot line')
            ->call('createLogShareLink');

        $share = LogViewerShare::query()->where('server_id', $server->id)->latest('id')->first();
        $this->assertNotNull($share);
        $this->assertSame('snapshot line', $share->content);

        $this->actingAs($user)
            ->get(route('log-viewer-shares.show', ['token' => $share->token]))
            ->assertOk()
            ->assertSee('snapshot line', false)
            ->assertSee(__('Shared log snapshot'), false);
    }

    public function test_log_viewer_pin_line_creates_database_row(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $fingerprint = str_repeat('a', 64);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->call('pinLogLine', $fingerprint, 'pinned note');

        $this->assertDatabaseHas('server_log_pins', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'line_fingerprint' => $fingerprint,
            'note' => 'pinned note',
        ]);
    }

    public function test_server_logs_select_log_source_updates_active_key(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server]);

        $keys = array_keys($component->instance()->availableLogSources());
        $this->assertGreaterThanOrEqual(1, count($keys), 'Log sources must include at least one key');

        if (count($keys) < 2) {
            $component->call('selectLogSource', $keys[0])->assertSet('logKey', $keys[0]);

            return;
        }

        $component
            ->call('selectLogSource', $keys[1])
            ->assertSet('logKey', $keys[1]);
    }

    public function test_server_logs_tail_line_count_persists_on_server_meta(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('logTailLines', 150)
            ->set('logDisplayLines', 8)
            ->call('applyLogTailLines');

        $server->refresh();
        $this->assertSame(150, $server->meta['log_ui_tail_lines'] ?? null);
        $this->assertSame(8, $server->meta['log_ui_display_lines'] ?? null);
    }

    public function test_server_logs_clear_display_clears_buffer(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', 'line one')
            ->set('remoteLogOutput', 'line one')
            ->set('remoteLogError', 'old error')
            ->call('clearLogDisplay')
            ->assertSet('remoteLogRaw', '')
            ->assertSet('remoteLogOutput', '')
            ->assertSet('remoteLogError', null);
    }

    public function test_server_logs_includes_per_site_sources_when_sites_exist(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server->fresh()]);

        $sources = $component->instance()->availableLogSources();
        $accessKey = 'site_'.$site->id.'_access';
        $errorKey = 'site_'.$site->id.'_error';

        $this->assertArrayHasKey($accessKey, $sources);
        $this->assertArrayHasKey($errorKey, $sources);
        $this->assertStringContainsString($site->nginxConfigBasename().'-access.log', $sources[$accessKey]['path']);
    }

    public function test_server_logs_regex_filter_matches_lines(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', "match-line\nskip\nmatch-other")
            ->set('logFilterUseRegex', true)
            ->set('logFilter', '^match')
            ->assertSet('logFilteredLines', 2);

        Livewire::actingAs($user)
            ->test(WorkspaceLogs::class, ['server' => $server])
            ->set('remoteLogRaw', "a\nb")
            ->set('logFilterUseRegex', true)
            ->set('logFilter', '(')
            ->assertSet('logFilterError', __('Invalid regular expression.'));
    }

    public function test_server_show_settings_tab_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
            ->assertSee('Connection & identity')
            ->assertSee('Use the tabs');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'alerts'])
            ->assertSee('Maintenance window');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'export'])
            ->assertSee('Download manifest (JSON)');

        Livewire::actingAs($user)
            ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'danger'])
            ->assertSee('Danger zone');
    }

    public function test_server_settings_redirects_bare_settings_url_to_connection_tab(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('servers.settings', ['server' => $server]))
            ->assertRedirect(route('servers.settings', ['server' => $server, 'section' => 'connection']));
    }

    public function test_server_settings_unknown_section_returns_404(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('servers.settings', ['server' => $server, 'section' => 'not-a-real-tab']))
            ->assertNotFound();
    }

    public function test_server_manage_workspace_renders(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Manage Me',
        ]);

        $this->actingAs($user)->get(route('servers.manage', $server))->assertOk()->assertSee('Manage Me');

        Livewire::actingAs($user)
            ->test(WorkspaceManage::class, ['server' => $server])
            ->assertSee('Manage')
            ->assertSee('Configuration files')
            ->assertSee('Service actions');
    }

    public function test_server_manage_config_preview_dispatches_background_job_when_enabled(): void
    {
        config(['server_manage.queue_remote_tasks' => true]);

        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceManage::class, ['server' => $server])
            ->call('previewConfig', 'nginx')
            ->assertSet('manageRemoteTaskId', fn ($id) => is_string($id) && strlen($id) > 0);

        Queue::assertPushed(ServerManageRemoteSshJob::class);
    }

    public function test_servers_show_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($otherUser->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertForbidden();
    }

    public function test_servers_can_be_destroyed_by_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSites::class, ['server' => $server])
            ->call('openRemoveServerModal')
            ->set('deleteConfirmName', $server->name)
            ->set('deletePhraseControl', 'DELETE')
            ->set('currentPassword', 'password')
            ->call('submitRemoveServer')
            ->assertRedirect(route('servers.index'));

        $this->assertModelMissing($server);
    }
}
