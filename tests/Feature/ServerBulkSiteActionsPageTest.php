<?php

declare(strict_types=1);

namespace Tests\Feature\ServerBulkSiteActionsPageTest;

use App\Jobs\RunSiteDeploymentJob;
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

test('bulk site actions panel renders on server sites page', function (): void {
    [$user, $server] = bulkSitesUserWithServer();

    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $this->actingAs($user)
        ->get(route('servers.sites', $server))
        ->assertOk()
        ->assertSee(__('Bulk site operations'))
        ->assertSee(__('Redeploy 1 site'));
});

test('redeploy all queues deploy jobs', function (): void {
    Queue::fake();

    [$user, $server] = bulkSitesUserWithServer();

    Site::factory()->count(2)->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $server])
        ->call('confirmRedeployAll')
        ->assertHasNoErrors();

    Queue::assertPushed(RunSiteDeploymentJob::class, 2);
});
