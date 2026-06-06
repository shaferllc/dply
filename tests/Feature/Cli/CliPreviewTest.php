<?php

declare(strict_types=1);

namespace Tests\Feature\Cli;

use App\Livewire\Servers\WorkspaceCliPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.cli', fn (): bool => false);
    Feature::define('workspace.cli_preview', fn (): bool => true);
    Feature::flushCache();
});

test('cli preview sidebar respects per-org override', function (): void {
    Feature::define('workspace.cli', fn (): bool => false);
    Feature::define('workspace.cli_preview', fn (): bool => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.cli_preview');

    $server = readyServer($user);

    expect(workspace_cli_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertDontSee('cli-preview', false);
});

test('admin vm servers page lists cli preview flag under cli group', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.cli_preview')
        ->assertSee(__('Coming soon preview'));
});

test('nav includes cli preview link when preview active', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);
    $this->actingAs($user);

    $cli = collect(server_workspace_nav_for_server($server))->firstWhere('key', 'cli');

    expect($cli)->not->toBeNull()
        ->and($cli['preview_only'] ?? false)->toBeTrue()
        ->and(server_workspace_nav_item_url($server, $cli))->toBe(route('servers.cli-preview', $server));
});

test('server workspace nav shows cli with soon badge when preview active', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $previewPath = parse_url(route('servers.cli-preview', $server), PHP_URL_PATH);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee($previewPath, false);
});

test('cli preview page renders coming soon panel', function (): void {
    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.cli-preview', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('dply CLI'));
});

test('cli preview page is hidden when full cli is enabled', function (): void {
    Feature::define('workspace.cli', fn (): bool => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.cli-preview', $server))
        ->assertNotFound();
});

test('livewire cli preview component aborts when preview inactive', function (): void {
    Feature::define('workspace.cli_preview', fn (): bool => false);
    Feature::flushCache();

    $user = ownerWithOrg();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCliPreview::class, ['server' => $server])
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
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
}
