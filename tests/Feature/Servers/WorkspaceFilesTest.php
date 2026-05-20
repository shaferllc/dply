<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Livewire\Servers\WorkspaceFiles;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserEntry;
use App\Support\Servers\FileBrowserListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class WorkspaceFilesTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['workspace.files'];

    private function actingOrgUser(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    private function readyServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()?->id,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'ssh_user' => 'dply',
        ]);
    }

    private function bindFakeReader(array $entries): void
    {
        $listing = new FileBrowserListing(
            path: '/home/dply',
            entries: $entries,
            truncated: false,
            totalCount: count($entries),
            filter: null,
        );

        $fake = $this->createMock(ServerFileBrowserRemoteReader::class);
        $fake->method('list')->willReturn($listing);
        $this->app->instance(ServerFileBrowserRemoteReader::class, $fake);
    }

    public function test_owner_can_load_server_files_page_with_default_path_under_deploy_home(): void
    {
        $user = $this->actingOrgUser('owner');
        $server = $this->readyServer($user);

        $this->bindFakeReader([
            new FileBrowserEntry('site.com', 'dir', 0, time(), 'drwxr-xr-x', 'dply', 'dply'),
            new FileBrowserEntry('.bashrc', 'file', 220, time(), '-rw-r--r--', 'dply', 'dply'),
        ]);

        $this->actingAs($user);

        Livewire::test(WorkspaceFiles::class, ['server' => $server])
            ->assertSet('path', '/home/dply')
            ->assertSet('viewAsRoot', false)
            ->assertSee('site.com')
            ->assertSee('.bashrc');
    }

    public function test_deployer_role_is_denied_on_server_files_page(): void
    {
        $user = $this->actingOrgUser('deployer');
        $server = $this->readyServer($user);

        $this->actingAs($user)
            ->get(route('servers.files', $server))
            ->assertForbidden();
    }

    public function test_admin_can_toggle_view_as_root(): void
    {
        $user = $this->actingOrgUser('admin');
        $server = $this->readyServer($user);

        $this->bindFakeReader([]);
        $this->actingAs($user);

        Livewire::test(WorkspaceFiles::class, ['server' => $server])
            ->assertSet('viewAsRoot', false)
            ->call('toggleViewAsRoot')
            ->assertSet('viewAsRoot', true)
            ->call('toggleViewAsRoot')
            ->assertSet('viewAsRoot', false);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $user->currentOrganization()->id,
            'action' => 'server.files.view_as_root.enabled',
        ]);
    }

    public function test_jump_to_normalizes_paths(): void
    {
        $user = $this->actingOrgUser('owner');
        $server = $this->readyServer($user);

        $this->bindFakeReader([]);
        $this->actingAs($user);

        Livewire::test(WorkspaceFiles::class, ['server' => $server])
            ->call('jumpTo', '/etc//nginx/')
            ->assertSet('path', '/etc/nginx');
    }
}
