<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteFilesTest;

use App\Livewire\Sites\Files;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\FileBrowserWriteResult;
use App\Services\Servers\ServerFileBrowserAtomicWriter;
use App\Services\Servers\ServerFileBrowserRemoteReader;
use App\Support\Servers\FileBrowserEntry;
use App\Support\Servers\FileBrowserFileRead;
use App\Support\Servers\FileBrowserListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingOrgUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function readyServerWithSite(User $user, array $siteOverrides = []): array
{
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'ssh_user' => 'dply',
    ]);

    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'repository_path' => '/home/dply/site.com',
        'deploy_strategy' => 'atomic',
    ], $siteOverrides));

    return [$server, $site];
}
test('owner loads site files at repository path', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing(
        path: '/home/dply/site.com',
        entries: [
            new FileBrowserEntry('current', 'link', 0, time(), 'lrwxrwxrwx', 'dply', 'dply', '/home/dply/site.com/releases/2026-05-11', true),
            new FileBrowserEntry('shared', 'dir', 0, time(), 'drwxr-xr-x', 'dply', 'dply'),
            new FileBrowserEntry('releases', 'dir', 0, time(), 'drwxr-xr-x', 'dply', 'dply'),
        ],
        truncated: false,
        totalCount: 3,
    ));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $this->actingAs($user);

    Livewire::test(Files::class, ['server' => $server, 'site' => $site])
        ->assertSet('path', '/home/dply/site.com')
        ->assertSee('shared')
        ->assertSee('releases')
        ->assertSee('current')
        ->assertSee('Follow');
});

test('openEntry follows directory symlink to resolved path inside site root', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $releasePath = '/home/dply/site.com/releases/20260707154231';
    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturnCallback(function (Server $server, string $path) use ($releasePath) {
        if ($path === $releasePath) {
            return new FileBrowserListing($releasePath, [
                new FileBrowserEntry('app', 'dir', 0, time(), 'drwxr-xr-x', 'dply', 'dply'),
            ], false, 1);
        }

        return new FileBrowserListing('/home/dply/site.com', [
            new FileBrowserEntry('current', 'link', 0, time(), 'lrwxrwxrwx', 'dply', 'dply', $releasePath, true),
        ], false, 1);
    });
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $this->actingAs($user);

    Livewire::test(Files::class, ['server' => $server, 'site' => $site])
        ->call('openEntry', 'current', $releasePath)
        ->assertSet('path', $releasePath)
        ->assertSee('app');
});

test('openEntry refuses directory symlink that escapes the site root', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing('/home/dply/site.com', [
        new FileBrowserEntry('escape', 'link', 0, time(), 'lrwxrwxrwx', 'dply', 'dply', '/etc', true),
    ], false, 1));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $this->actingAs($user);

    Livewire::test(Files::class, ['server' => $server, 'site' => $site])
        ->call('openEntry', 'escape', '/etc')
        ->assertSet('path', '/home/dply/site.com');
});

test('edit inside releases warns before saving', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing('/home/dply/site.com/releases/2026-05-11', [], false, 0));
    $reader->method('read')->willReturn(new FileBrowserFileRead(
        path: '/home/dply/site.com/releases/2026-05-11/.env',
        size: 12,
        mtime: 1715000000,
        sha256: str_repeat('a', 64),
        mime: 'text/plain',
        isBinary: false,
        content: 'APP_ENV=prod',
    ));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $writer = $this->createMock(ServerFileBrowserAtomicWriter::class);
    $writer->expects($this->never())->method('write');
    $this->app->instance(ServerFileBrowserAtomicWriter::class, $writer);

    $this->actingAs($user);

    Livewire::test(Files::class, [
        'server' => $server,
        'site' => $site->fresh(),
    ])
        ->set('path', '/home/dply/site.com/releases/2026-05-11')
        ->call('startEdit', '.env')
        ->assertSet('editingWillBeOverwrittenOnDeploy', true)
        ->call('saveEdit', false)
        ->assertSet('pendingOverwriteWarning', true);
});

test('edit in a simple checkout warns before saving', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user, ['deploy_strategy' => 'simple']);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing('/home/dply/site.com/app', [], false, 0));
    $reader->method('read')->willReturn(new FileBrowserFileRead(
        path: '/home/dply/site.com/app/helpers.php',
        size: 20,
        mtime: 1715000000,
        sha256: str_repeat('b', 64),
        mime: 'text/x-php',
        isBinary: false,
        content: '<?php',
    ));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $writer = $this->createMock(ServerFileBrowserAtomicWriter::class);
    $writer->expects($this->never())->method('write');
    $this->app->instance(ServerFileBrowserAtomicWriter::class, $writer);

    $this->actingAs($user);

    Livewire::test(Files::class, [
        'server' => $server,
        'site' => $site->fresh(),
    ])
        ->set('path', '/home/dply/site.com/app')
        ->call('startEdit', 'helpers.php')
        ->assertSet('editingWillBeOverwrittenOnDeploy', true)
        ->assertSee('The next deploy will overwrite this file')
        ->call('saveEdit', false)
        ->assertSet('pendingOverwriteWarning', true);
});

test('edit under shared does not warn about deploy overwrite', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing('/home/dply/site.com/shared', [], false, 0));
    $reader->method('read')->willReturn(new FileBrowserFileRead(
        path: '/home/dply/site.com/shared/.env',
        size: 12,
        mtime: 1715000000,
        sha256: str_repeat('a', 64),
        mime: 'text/plain',
        isBinary: false,
        content: 'APP_ENV=prod',
    ));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $this->actingAs($user);

    Livewire::test(Files::class, [
        'server' => $server,
        'site' => $site->fresh(),
    ])
        ->set('path', '/home/dply/site.com/shared')
        ->call('startEdit', '.env')
        ->assertSet('editingWillBeOverwrittenOnDeploy', false)
        ->assertDontSee('The next deploy will overwrite this file');
});

test('save conflict when remote sha drifted', function () {
    $user = actingOrgUser('owner');
    [$server, $site] = readyServerWithSite($user);

    $reader = $this->createMock(ServerFileBrowserRemoteReader::class);
    $reader->method('list')->willReturn(new FileBrowserListing('/home/dply/site.com/shared', [], false, 0));
    $reader->method('read')->willReturn(new FileBrowserFileRead(
        path: '/home/dply/site.com/shared/.env',
        size: 12,
        mtime: 1715000000,
        sha256: str_repeat('a', 64),
        mime: 'text/plain',
        isBinary: false,
        content: 'APP_ENV=prod',
    ));
    $this->app->instance(ServerFileBrowserRemoteReader::class, $reader);

    $writer = $this->createMock(ServerFileBrowserAtomicWriter::class);
    $writer->method('write')->willReturn(new FileBrowserWriteResult(
        ok: false,
        conflictReason: 'CONFLICT',
        newSha256: '',
        newMtime: 0,
    ));
    $this->app->instance(ServerFileBrowserAtomicWriter::class, $writer);

    $this->actingAs($user);

    Livewire::test(Files::class, [
        'server' => $server,
        'site' => $site->fresh(),
    ])
        ->set('path', '/home/dply/site.com/shared')
        ->call('startEdit', '.env')
        ->set('editingContent', 'APP_ENV=staging')
        ->call('saveEdit')
        ->assertSet('showConflictModal', true);
});
