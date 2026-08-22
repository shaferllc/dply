<?php

namespace Tests\Feature\Api\ServerlessWorkersApiTest;

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
function workerSiteWithToken(array $abilities = ['serverless.read', 'serverless.write'], array $serverless = []): array
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

it('reports the engine, the worker list, and the last queue tick', function () {
    [$site, $token] = workerSiteWithToken(serverless: [
        'queue_worker_enabled' => true,
        'workers' => [[
            'id' => 'w1',
            'name' => 'queue-default',
            'command' => 'php artisan queue:work',
            'concurrency' => 2,
            'restart_policy' => 'always',
            'enabled' => true,
        ]],
    ]);

    FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'task' => 'queue',
        'success' => true,
        'status_code' => 200,
        'duration_ms' => 42,
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/workers")
        ->assertOk()
        ->assertJsonPath('data.engine_enabled', true)
        ->assertJsonPath('data.last_tick.status', 'ok')
        ->assertJsonPath('data.workers.0.name', 'queue-default')
        // Engine on + last tick ok ⇒ the worker reads as running.
        ->assertJsonPath('data.workers.0.status', 'running');
});

it('falls back to the legacy background flag and keeps it in sync', function () {
    // A pre-split site has only the bundled flag, which reads as "both tasks
    // on" — so disabling the queue leaves it set for the scheduler half.
    [$site, $token] = workerSiteWithToken(serverless: ['background_enabled' => true]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/workers")
        ->assertJsonPath('data.engine_enabled', true);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/workers", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.engine_enabled', false);

    expect($site->fresh()->meta['serverless']['background_enabled'])->toBeTrue();
});

it('clears the bundled flag once neither task is on', function () {
    [$site, $token] = workerSiteWithToken(serverless: [
        'queue_worker_enabled' => true,
        'scheduler_enabled' => false,
        'background_enabled' => true,
    ]);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/workers", ['enabled' => false])
        ->assertOk();

    expect($site->fresh()->meta['serverless']['background_enabled'])->toBeFalse();
});

it('leaves the bundled flag on when the scheduler still needs it', function () {
    [$site, $token] = workerSiteWithToken(serverless: ['scheduler_enabled' => true]);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/workers", ['enabled' => false])
        ->assertOk();

    expect($site->fresh()->meta['serverless']['background_enabled'])->toBeTrue();
});

it('adds, patches, and removes a worker, addressed by name', function () {
    [$site, $token] = workerSiteWithToken();

    $id = $this->withToken($token)
        ->postJson("/api/v1/serverless/sites/{$site->id}/workers", [
            'name' => 'queue-default',
            'command' => 'php artisan queue:work',
        ])
        ->assertCreated()
        ->assertJsonPath('data.concurrency', 1)
        ->assertJsonPath('data.restart_policy', 'on-failure')
        ->assertJsonPath('data.enabled', true)
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/workers/queue-default", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.enabled', false)
        // A patch touches only what it names.
        ->assertJsonPath('data.command', 'php artisan queue:work');

    $this->withToken($token)
        ->deleteJson("/api/v1/serverless/sites/{$site->id}/workers/{$id}")
        ->assertOk();

    expect($site->fresh()->meta['serverless']['workers'])->toBe([]);
});

it('404s an unknown worker and validates the restart policy', function () {
    [$site, $token] = workerSiteWithToken();

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/workers/nope", ['enabled' => true])
        ->assertNotFound();

    $this->withToken($token)
        ->postJson("/api/v1/serverless/sites/{$site->id}/workers", [
            'name' => 'w',
            'command' => 'x',
            'restart_policy' => 'sometimes',
        ])
        ->assertStatus(422);
});

it('needs serverless.write to change anything', function () {
    [$site, $readToken] = workerSiteWithToken(['serverless.read']);

    $this->withToken($readToken)
        ->getJson("/api/v1/serverless/sites/{$site->id}/workers")
        ->assertOk();

    $this->withToken($readToken)
        ->putJson("/api/v1/serverless/sites/{$site->id}/workers", ['enabled' => true])
        ->assertForbidden();

    // Ticking runs customer code — its own ability, not the write scope.
    $this->withToken($readToken)
        ->postJson("/api/v1/serverless/sites/{$site->id}/workers/tick")
        ->assertForbidden();
});
