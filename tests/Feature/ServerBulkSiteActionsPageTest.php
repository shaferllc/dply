<?php

declare(strict_types=1);

namespace Tests\Feature\ServerBulkSiteActionsPageTest;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Servers\WorkspaceSites;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.bulk_site_actions');

function bulkSitesUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    return [$user, $server, $org];
}

test('bulk site actions appear when sites are selected on server sites page', function (): void {
    [$user, $server] = bulkSitesUserWithServer();

    Site::factory()->count(2)->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $this->actingAs($user)
        ->get(route('servers.sites', $server))
        ->assertOk()
        ->assertSee(__('Site directory'))
        ->assertDontSee(__('Redeploy 2 sites'));

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $server])
        ->set('selectedSiteIds', $server->fresh()->sites->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->assertSee(__('Redeploy 2 sites'));
});

test('redeploy selected queues deploy jobs', function (): void {
    Queue::fake();

    [$user, $server] = bulkSitesUserWithServer();

    Site::factory()->count(2)->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $selectedIds = $server->fresh()->sites->pluck('id')->map(fn ($id) => (string) $id)->all();

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $server])
        ->set('selectedSiteIds', $selectedIds)
        ->call('confirmRedeployAll')
        ->assertHasNoErrors();

    Queue::assertPushed(RunSiteDeploymentJob::class, 2);
});
