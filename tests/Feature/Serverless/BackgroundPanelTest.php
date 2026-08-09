<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\BackgroundPanelTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Jobs\ServerlessQueueSlotJob;
use App\Modules\Serverless\Livewire\BackgroundPanel;
use App\Modules\Serverless\Models\ServerlessFailedJob;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Site} */
function panelSite(array $queueConfig = ['enabled' => true]): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas.example',
                'namespace' => 'fn-test',
                'access_key' => 'uuid:secret',
            ],
        ],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'command_secret' => 'test-command-secret',
                'action_name' => 'demo',
                'action_url' => 'https://faas.example/api/v1/web/fn-test/default/demo',
                'background_enabled' => true,
                'queue' => $queueConfig,
            ],
        ],
    ]);

    return [$user, $site];
}

function seedFailure(Site $site, array $overrides = []): ServerlessFailedJob
{
    return ServerlessFailedJob::query()->create(array_merge([
        'site_id' => $site->id,
        'uuid' => 'uuid-'.bin2hex(random_bytes(3)),
        'queue' => 'default',
        'job_class' => 'App\\Jobs\\SendInvoice',
        'exception_message' => 'Connection refused',
        'failed_at' => now(),
    ], $overrides));
}

/** A successful command invocation (used by retry). */
function fakeCommandOk(): void
{
    Http::fake([
        'faas.example/*' => Http::response([
            'activationId' => 'act-1',
            'duration' => 20,
            'annotations' => [],
            'logs' => [],
            'response' => [
                'success' => true,
                'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'dply ran queue:retry — exit 0'],
            ],
        ]),
    ]);
}

beforeEach(function () {
    Cache::flush();
});

test('it saves queue concurrency', function () {
    [$user, $site] = panelSite();

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->set('max_concurrency', 12)
        ->call('saveConcurrency')
        ->assertHasNoErrors();

    expect(app(ServerlessQueuePump::class)->config($site->fresh())['max_concurrency'])->toBe(12);
});

test('it rejects a concurrency above the hard ceiling', function () {
    [$user, $site] = panelSite();

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->set('max_concurrency', ServerlessQueuePump::MAX_CONCURRENCY_CEILING + 1)
        ->call('saveConcurrency')
        ->assertHasErrors('max_concurrency');

    // Nothing was written — the pump still reports the default.
    expect(app(ServerlessQueuePump::class)->config($site->fresh())['max_concurrency'])
        ->toBe(ServerlessQueuePump::DEFAULT_MAX_CONCURRENCY);
});

test('it rejects a concurrency below one', function () {
    [$user, $site] = panelSite();

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->set('max_concurrency', 0)
        ->call('saveConcurrency')
        ->assertHasErrors('max_concurrency');
});

test('the input mounts with the concurrency actually in force', function () {
    [$user, $site] = panelSite(['enabled' => true, 'max_concurrency' => 7]);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->assertSet('max_concurrency', 7);
});

test('it lists failed jobs', function () {
    [$user, $site] = panelSite();
    seedFailure($site, ['job_class' => 'App\\Jobs\\SendInvoice']);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->assertSee('SendInvoice')
        ->assertSee('Connection refused');
});

test('it does not list another site failed jobs', function () {
    [$user, $site] = panelSite();
    [, $other] = panelSite();
    seedFailure($other, ['job_class' => 'App\\Jobs\\NotMine']);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->assertDontSee('NotMine');
});

test('retrying all failed jobs invokes the function and marks them re-queued', function () {
    Bus::fake();
    fakeCommandOk();
    [$user, $site] = panelSite();
    $failure = seedFailure($site);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('retryFailedJobs');

    expect($failure->fresh()->wasRetried())->toBeTrue();

    // The retry command carries the signed command header, and asks for all.
    Http::assertSent(fn ($request): bool => data_get($request->data(), '__ow_headers.x-dply-run') === 'queue-retry'
        && data_get($request->data(), '__ow_headers.x-dply-queue-retry-id') === 'all'
        && data_get($request->data(), '__ow_headers.x-dply-secret') === 'test-command-secret');

    // And the pump is woken so the re-queued job drains now, not in a minute.
    Bus::assertDispatched(ServerlessQueueSlotJob::class);
});

test('retrying one job targets only that uuid', function () {
    Bus::fake();
    fakeCommandOk();
    [$user, $site] = panelSite();
    $mine = seedFailure($site, ['uuid' => 'target-uuid']);
    $other = seedFailure($site, ['uuid' => 'other-uuid']);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('retryFailedJobs', 'target-uuid');

    expect($mine->fresh()->wasRetried())->toBeTrue();
    expect($other->fresh()->wasRetried())->toBeFalse();

    Http::assertSent(fn ($request): bool => data_get($request->data(), '__ow_headers.x-dply-queue-retry-id') === 'target-uuid');
});

test('retrying a job from another site is refused', function () {
    Bus::fake();
    fakeCommandOk();
    [$user, $site] = panelSite();
    [, $other] = panelSite();
    $foreign = seedFailure($other, ['uuid' => 'foreign-uuid']);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('retryFailedJobs', 'foreign-uuid');

    expect($foreign->fresh()->wasRetried())->toBeFalse();
    Http::assertNothingSent();
});

test('a failed retry leaves the job un-marked', function () {
    // The function rejected it — claiming the job was re-queued would be a lie.
    Bus::fake();
    Http::fake([
        'faas.example/*' => Http::response([
            'activationId' => 'act-1',
            'duration' => 5,
            'annotations' => [],
            'logs' => [],
            'response' => ['success' => false, 'result' => ['statusCode' => 500, 'headers' => [], 'body' => 'boom']],
        ]),
    ]);
    [$user, $site] = panelSite();
    $failure = seedFailure($site);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('retryFailedJobs');

    expect($failure->fresh()->wasRetried())->toBeFalse();
});

test('dismissing a failure removes only that row', function () {
    [$user, $site] = panelSite();
    $gone = seedFailure($site);
    $kept = seedFailure($site);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('dismissFailedJob', $gone->id);

    expect(ServerlessFailedJob::query()->find($gone->id))->toBeNull();
    expect(ServerlessFailedJob::query()->find($kept->id))->not->toBeNull();
});

test('dismissing cannot reach another site row', function () {
    [$user, $site] = panelSite();
    [, $other] = panelSite();
    $foreign = seedFailure($other);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('dismissFailedJob', $foreign->id);

    expect(ServerlessFailedJob::query()->find($foreign->id))->not->toBeNull();
});

test('clearing removes this site failures and leaves others', function () {
    [$user, $site] = panelSite();
    [, $other] = panelSite();
    seedFailure($site);
    seedFailure($site);
    $foreign = seedFailure($other);

    Livewire::actingAs($user)
        ->test(BackgroundPanel::class, ['site' => $site])
        ->call('clearFailedJobs');

    expect(ServerlessFailedJob::query()->where('site_id', $site->id)->count())->toBe(0);
    expect(ServerlessFailedJob::query()->find($foreign->id))->not->toBeNull();
});
