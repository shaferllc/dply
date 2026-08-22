<?php

namespace Tests\Feature\Api\ServerlessScheduleApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: string}
 */
function scheduleSiteWithToken(array $abilities = ['serverless.read', 'serverless.write'], array $serverless = []): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => array_merge(['action_name' => 'checkout'], $serverless),
        ],
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

function scheduleTick(Site $site, bool $success, string $task = 'schedule'): FunctionInvocation
{
    return FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'task' => $task,
        'status_code' => $success ? 200 : 500,
        'success' => $success,
        'duration_ms' => 12,
        'result_excerpt' => $success ? 'ok' : 'boom',
    ]);
}

it('reports the scheduler switch and its firing history', function () {
    [$site, $token] = scheduleSiteWithToken(serverless: ['scheduler_enabled' => true]);

    scheduleTick($site, true);
    scheduleTick($site, false);
    // Queue ticks belong to the Workers tab — they must not leak in here.
    scheduleTick($site, true, 'queue');

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.total_ticks', 2)
        ->assertJsonCount(2, 'data.ticks');
});

it('filters to failed ticks while still counting them all', function () {
    [$site, $token] = scheduleSiteWithToken();

    scheduleTick($site, true);
    scheduleTick($site, false);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/schedule?failed=1")
        ->assertOk()
        ->assertJsonCount(1, 'data.ticks')
        ->assertJsonPath('data.ticks.0.status', 'failed')
        ->assertJsonPath('data.total_ticks', 2);
});

it('flips the scheduler and keeps the bundled flag in sync with the queue', function () {
    [$site, $token] = scheduleSiteWithToken(serverless: ['queue_worker_enabled' => true]);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/schedule", ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true);

    expect($site->fresh()->meta['serverless']['scheduler_enabled'])->toBeTrue();

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/schedule", ['enabled' => false])
        ->assertOk();

    // The queue worker is still on, so the legacy bundled flag stays true.
    expect($site->fresh()->meta['serverless']['background_enabled'])->toBeTrue();
});

it('needs the write scope to flip it and the invoke scope to tick', function () {
    [$site, $readToken] = scheduleSiteWithToken(['serverless.read']);

    $this->withToken($readToken)
        ->getJson("/api/v1/serverless/sites/{$site->id}/schedule")
        ->assertOk();

    $this->withToken($readToken)
        ->putJson("/api/v1/serverless/sites/{$site->id}/schedule", ['enabled' => true])
        ->assertForbidden();

    $this->withToken($readToken)
        ->postJson("/api/v1/serverless/sites/{$site->id}/schedule/tick")
        ->assertForbidden();
});
