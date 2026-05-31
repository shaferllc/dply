<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Livewire\Servers\WorkspaceBackupsPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.backups', fn (): bool => false);
    Feature::define('workspace.backups_preview', fn (): bool => true);
    Feature::flushCache();
});

test('backups preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/backups', false);
});

test('site workspace sidebar shows backups with soon badge when preview active', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.cron', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Backups'))
        ->assertSee(__('Soon'))
        ->assertSee(route('sites.backups', [$server, $site]), false);
});

test('site backups route renders coming soon panel in site workspace shell', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $this->actingAs($user)
        ->get(route('sites.backups', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Backups'))
        ->assertSee(__('Recurring schedules'))
        ->assertDontSee(route('servers.backups', ['server' => $server, 'site' => $site->id]), false);
});

test('backups route renders coming soon panel when preview active', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.backups', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Backups'))
        ->assertSee(__('Recurring schedules'));
});

test('admin vm servers page lists backups preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.backups_preview')
        ->assertSee(__('Coming soon preview'));
});

test('backups preview alias redirects to canonical route', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.backups-preview', $server))
        ->assertRedirect(route('servers.backups', $server));
});

test('backups preview component redirects when preview active', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBackupsPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.backups', $server));
});

test('backups route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.backups_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = backupsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.backups', $server))
        ->assertNotFound();
});

test('site workspace hides backups when preview and full feature are off', function (): void {
    Feature::define('workspace.backups_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = backupsPreviewUserWithServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    $ids = collect(SiteSettingsSidebar::items($site->fresh(), $server))->pluck('id')->all();

    expect($ids)->not->toContain('backups');
});

test('backups preview respects per-org override', function (): void {
    [$user, $server] = backupsPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.backups_preview');

    expect(workspace_backups_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.backups', $server))
        ->assertNotFound();
});

function backupsPreviewUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    return [$user, $server];
}
