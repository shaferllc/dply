<?php

declare(strict_types=1);

namespace Tests\Unit\ServerBulkSiteActionsTest;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerBulkSiteActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('preview counts deployable sites only', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'ready-site',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'pending-site',
        'status' => Site::STATUS_PENDING,
    ]);

    $preview = app(ServerBulkSiteActions::class)->preview($server->fresh());

    expect($preview['redeploy_count'])->toBe(1)
        ->and($preview['site_names'])->toBe(['ready-site']);
});

test('redeploy all queues jobs for deployable sites', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    Site::factory()->count(2)->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $result = app(ServerBulkSiteActions::class)->redeployAll($server->fresh(), $user);

    expect($result['queued'])->toBe(2);
    Queue::assertPushed(RunSiteDeploymentJob::class, 2);
});
