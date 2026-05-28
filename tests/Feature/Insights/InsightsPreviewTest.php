<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Livewire\Servers\WorkspaceInsightsPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.insights', fn (): bool => false);
    Feature::define('workspace.insights_preview', fn (): bool => true);
    Feature::flushCache();
});

function insightsPreviewUserWithServer(): array
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

test('insights preview sidebar shows soon badge when full insights is off', function (): void {
    [$user, $server] = insightsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/insights', false);
});

test('insights route renders coming soon panel when preview active', function (): void {
    [$user, $server] = insightsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.insights', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Server insights'))
        ->assertSee(__('Continuous checks'));
});

test('insights preview alias redirects to canonical insights route', function (): void {
    [$user, $server] = insightsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.insights-preview', $server))
        ->assertRedirect(route('servers.insights', $server));
});

test('insights preview component redirects when preview active', function (): void {
    [$user, $server] = insightsPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceInsightsPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.insights', $server));
});

test('insights preview component is hidden when full insights is enabled', function (): void {
    Feature::define('workspace.insights', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = insightsPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceInsightsPreview::class, ['server' => $server])
        ->assertStatus(404);
});

test('insights route is hidden when preview and full insights are off', function (): void {
    Feature::define('workspace.insights_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = insightsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.insights', $server))
        ->assertNotFound();
});
