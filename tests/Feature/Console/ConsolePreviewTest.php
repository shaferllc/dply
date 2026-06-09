<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Livewire\Servers\WorkspaceConsolePreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.console', fn (): bool => false);
    Feature::define('workspace.console_preview', fn (): bool => true);
    Feature::flushCache();
});

test('console preview sidebar respects per-org override', function (): void {
    Feature::define('workspace.console', fn (): bool => false);
    Feature::define('workspace.console_preview', fn (): bool => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.console_preview');

    $server = readyServer($user);

    expect(workspace_console_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertDontSee('console-preview', false);
});

test('admin vm servers page lists console preview flag under console group', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.console_preview')
        ->assertSee(__('Coming soon preview'));
});

test('server workspace nav shows console with soon badge when preview active', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('console-preview', false);
});

test('console preview page renders coming soon panel', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.console-preview', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Browser console'));
});

test('console preview page is hidden when full console is enabled', function (): void {
    Feature::define('workspace.console', fn (): bool => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.console-preview', $server))
        ->assertNotFound();
});

test('floating console soon button appears when preview active and console off', function (): void {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('Console'))
        ->assertSee(__('Soon'))
        ->assertSee(__('Browser console — coming soon'), false);
});

test('floating console soon button hidden when preview inactive', function (): void {
    Feature::define('workspace.console_preview', fn (): bool => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('Browser console — coming soon'), false);
});

test('livewire console preview component aborts when preview inactive', function (): void {
    Feature::define('workspace.console_preview', fn (): bool => false);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceConsolePreview::class, ['server' => $server])
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
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'ssh_user' => 'deploy',
        'name' => 'preview-server',
    ]);
}
