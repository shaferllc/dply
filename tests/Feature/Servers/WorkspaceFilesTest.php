<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceFilesTest;
use App\Livewire\Servers\WorkspaceFiles;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserEntry;
use App\Support\Servers\FileBrowserListing;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

function actingOrgUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function readyServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'ssh_user' => 'dply',
    ]);
}
function bindFakeReader(array $entries): void
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
test('owner can load server files page with default path under deploy home', function () {
    $user = actingOrgUser('owner');
    $server = readyServer($user);

    bindFakeReader([
        new FileBrowserEntry('site.com', 'dir', 0, time(), 'drwxr-xr-x', 'dply', 'dply'),
        new FileBrowserEntry('.bashrc', 'file', 220, time(), '-rw-r--r--', 'dply', 'dply'),
    ]);

    $this->actingAs($user);

    Livewire::test(WorkspaceFiles::class, ['server' => $server])
        ->assertSet('path', '/home/dply')
        ->assertSet('viewAsRoot', false)
        ->assertSee('site.com')
        ->assertSee('.bashrc');
});
test('deployer role is denied on server files page', function () {
    $user = actingOrgUser('deployer');
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.files', $server))
        ->assertForbidden();
});
test('admin can toggle view as root', function () {
    $user = actingOrgUser('admin');
    $server = readyServer($user);

    bindFakeReader([]);
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
});
test('jump to normalizes paths', function () {
    $user = actingOrgUser('owner');
    $server = readyServer($user);

    bindFakeReader([]);
    $this->actingAs($user);

    Livewire::test(WorkspaceFiles::class, ['server' => $server])
        ->call('jumpTo', '/etc//nginx/')
        ->assertSet('path', '/etc/nginx');
});
