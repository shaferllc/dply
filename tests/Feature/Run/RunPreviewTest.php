<?php

declare(strict_types=1);

namespace Tests\Feature\Run;

use App\Livewire\Servers\WorkspaceRunPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.run', fn (): bool => false);
    Feature::define('workspace.run_preview', fn (): bool => true);
    Feature::flushCache();
});

test('run preview sidebar respects per-org override', function (): void {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.run_preview');

    $server = readyServer($user);

    expect(workspace_run_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertDontSee('run-preview', false);
});

test('admin vm servers page lists run preview flag under run group', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.run_preview')
        ->assertSee(__('Coming soon preview'));
});

test('server workspace nav shows run with soon badge when preview active', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('run-preview', false);
});

test('run preview page renders coming soon panel', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.run-preview', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Saved commands'));
});

test('run preview page is hidden when full run is enabled', function (): void {
    Feature::define('workspace.run', fn (): bool => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.run-preview', $server))
        ->assertNotFound();
});

test('livewire run preview component aborts when preview inactive', function (): void {
    Feature::define('workspace.run_preview', fn (): bool => false);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceRunPreview::class, ['server' => $server])
        ->assertNotFound();
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'ssh_user' => 'deploy',
        'name' => 'preview-server',
    ]);
}
