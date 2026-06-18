<?php

declare(strict_types=1);

namespace Tests\Feature\FleetOpsCopilotPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\OpsCopilot\Services\OpsCopilotContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures('surface.fleet', 'global.ops_copilot');

test('ops copilot page renders suggestions for failed deploy', function () {
    [$user, $org, $server] = makeOrgWithServer();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Broken API',
        'runtime' => 'php',
    ]);

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'copilot-fail-1',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted',
        'exit_code' => 1,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    $this->actingAs($user)->get(route('fleet.copilot', ['site' => $site->id]))
        ->assertOk()
        ->assertSee(__('Ops Copilot'))
        ->assertSee('Broken API')
        ->assertSee('PHP memory limit exhausted');
});

test('ops copilot route is hidden when flag is off', function () {
    [$user, $org, $server] = makeOrgWithServer();

    Feature::define('global.ops_copilot', fn () => false);
    Feature::flushCache();

    $this->actingAs($user)->get(route('fleet.copilot'))
        ->assertStatus(400);
});

test('context builder lists candidate sites with failures', function () {
    [$user, $org, $server] = makeOrgWithServer();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Worker',
    ]);

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'copilot-fail-2',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'error',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $candidates = app(OpsCopilotContextBuilder::class)->candidateSites($org);

    expect($candidates)->toHaveCount(1);
    expect($candidates->first()['name'])->toBe('Worker');
});

/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function makeOrgWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Prod VM',
    ]);

    return [$user, $org, $server];
}
