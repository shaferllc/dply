<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessQueuePumpTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Jobs\ServerlessQueueSlotJob;
use App\Modules\Serverless\Models\ServerlessFailedJob;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function pumpSite(array $queueConfig = ['enabled' => true]): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

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

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        // A backend the pump will actually drain. Without this the pump
        // correctly refuses (see ServerlessQueueBackendTest) and every
        // slot assertion below would be testing the refusal instead.
        'env_file_content' => "QUEUE_CONNECTION=redis\nREDIS_HOST=redis.example\n",
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'command_secret' => 'test-command-secret',
                'action_name' => 'demo',
                'queue' => $queueConfig,
            ],
        ],
    ]);
}

/**
 * Fake the OpenWhisk activation so the real InvokeFunctionTick and
 * FunctionInvoker run. Faking here rather than mocking the tick means the
 * handler's JSON contract, parseReport(), and the slot's decision are all
 * covered by these tests — the seam is the network, not our own code.
 *
 * @param  array<string, mixed>  $report  the handler's slot body
 */
function fakeSlotReport(array $report): void
{
    $body = array_merge(
        ['dply_queue_slot' => true, 'ok' => true, 'processed' => 0, 'failed' => 0, 'remaining' => 0, 'duration_ms' => 12],
        $report,
    );

    Http::fake([
        'faas.example/*' => Http::response([
            'activationId' => 'act-'.bin2hex(random_bytes(4)),
            'duration' => 120,
            'annotations' => [],
            'logs' => [],
            'response' => [
                'success' => (bool) $body['ok'],
                'result' => [
                    'statusCode' => $body['ok'] ? 200 : 500,
                    'headers' => ['content-type' => 'application/json; charset=utf-8'],
                    'body' => (string) json_encode($body),
                ],
            ],
        ]),
    ]);
}

/** A slot invocation that never reaches the function at all. */
function fakeSlotUnreachable(): void
{
    Http::fake(['faas.example/*' => Http::response(['error' => 'gateway'], 502)]);
}

beforeEach(function () {
    Cache::flush();
});

test('wake opens slots and dispatches them', function () {
    Bus::fake();
    $site = pumpSite();

    $opened = app(ServerlessQueuePump::class)->wake($site);

    expect($opened)->toBe(ServerlessQueuePump::WAKE_RAMP);
    Bus::assertDispatchedTimes(ServerlessQueueSlotJob::class, ServerlessQueuePump::WAKE_RAMP);
});

test('wake does nothing when the pump is disabled', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => false]);

    expect(app(ServerlessQueuePump::class)->wake($site))->toBe(0);
    Bus::assertNothingDispatched();
});

test('falls back to the legacy background toggle when no pump config exists', function () {
    // Sites that enabled background processing before the pump shipped must
    // keep draining without anyone re-enabling anything.
    $site = pumpSite();
    $meta = $site->meta;
    unset($meta['serverless']['queue']);
    $meta['serverless']['background_enabled'] = true;
    $site->forceFill(['meta' => $meta])->save();

    expect(app(ServerlessQueuePump::class)->config($site->fresh())['enabled'])->toBeTrue();
});

test('concurrency never exceeds the configured ceiling', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 3]);
    $pump = app(ServerlessQueuePump::class);

    // Far more wake attempts than the ceiling allows.
    $granted = 0;
    for ($i = 0; $i < 25; $i++) {
        $granted += $pump->tryOpenSlot($site) ? 1 : 0;
    }

    expect($granted)->toBe(3);
    expect($pump->activeSlots($site))->toBe(3);
});

test('max_concurrency is clamped to the hard ceiling', function () {
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 5000]);

    expect(app(ServerlessQueuePump::class)->config($site)['max_concurrency'])
        ->toBe(ServerlessQueuePump::MAX_CONCURRENCY_CEILING);
});

test('closing a slot returns capacity', function () {
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 1]);
    $pump = app(ServerlessQueuePump::class);

    expect($pump->tryOpenSlot($site))->toBeTrue();
    expect($pump->tryOpenSlot($site))->toBeFalse();

    $pump->closeSlot($site);

    expect($pump->activeSlots($site))->toBe(0);
    expect($pump->tryOpenSlot($site))->toBeTrue();
});

test('a slot with work remaining hands its reservation to a successor', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 4]);
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport(['processed' => 10, 'remaining' => 3]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    // The successor inherits the reservation — it is NOT released and
    // re-acquired, or another wake could steal the capacity in between.
    expect($pump->activeSlots($site))->toBe(1);
    Bus::assertDispatched(ServerlessQueueSlotJob::class);
});

test('a slot that drains the queue releases its reservation and stops', function () {
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport(['processed' => 4, 'remaining' => 0]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
    Bus::assertNotDispatched(ServerlessQueueSlotJob::class);
});

test('a deep backlog ramps up additional slots', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 8]);
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport(['processed' => 10, 'remaining' => ServerlessQueuePump::RAMP_THRESHOLD + 50]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    // Its own successor, plus the ramp opened by wake().
    expect($pump->activeSlots($site))->toBe(1 + ServerlessQueuePump::WAKE_RAMP);
});

test('an unreachable function releases the slot instead of hot-looping', function () {
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotUnreachable();

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
    Bus::assertNotDispatched(ServerlessQueueSlotJob::class);
});

test('an uncountable queue keeps going while the slot is still doing work', function () {
    // remaining=null means the driver could not report depth — an older
    // handler, or a queue driver with no size(). Work was done, so assume
    // there may be more.
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport(['ok' => true, 'processed' => 3, 'remaining' => null]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(1);
    Bus::assertDispatchedTimes(ServerlessQueueSlotJob::class, 1);
});

test('an uncountable queue stops once a slot processes nothing', function () {
    // The termination condition for the null-depth case: without it, an old
    // handler that drains to empty would re-invoke forever.
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport(['ok' => true, 'processed' => 0, 'remaining' => null]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
    Bus::assertNotDispatched(ServerlessQueueSlotJob::class);
});

test('a slot released mid-flight by disabling the pump stops cleanly', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => true]);
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    $meta = $site->meta;
    $meta['serverless']['queue']['enabled'] = false;
    $site->forceFill(['meta' => $meta])->save();

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
    Bus::assertNotDispatched(ServerlessQueueSlotJob::class);
});

test('a throwing invoke still gives the reservation back', function () {
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    Http::fake(['faas.example/*' => fn () => throw new \RuntimeException('connection reset')]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
});

test('the wake endpoint opens slots for a correctly signed ping', function () {
    Bus::fake();
    $site = pumpSite();

    $this->withHeader('X-Dply-Secret', 'test-command-secret')
        ->postJson(route('hooks.functions.queue.wake', ['site' => $site]))
        ->assertStatus(202)
        ->assertJson(['woken' => true, 'slots_opened' => ServerlessQueuePump::WAKE_RAMP]);

    Bus::assertDispatchedTimes(ServerlessQueueSlotJob::class, ServerlessQueuePump::WAKE_RAMP);
});

test('the wake endpoint rejects a wrong secret', function () {
    Bus::fake();
    $site = pumpSite();

    $this->withHeader('X-Dply-Secret', 'not-the-secret')
        ->postJson(route('hooks.functions.queue.wake', ['site' => $site]))
        ->assertStatus(401);

    Bus::assertNothingDispatched();
});

test('the wake endpoint rejects a missing secret', function () {
    Bus::fake();
    $site = pumpSite();

    $this->postJson(route('hooks.functions.queue.wake', ['site' => $site]))
        ->assertStatus(401);

    Bus::assertNothingDispatched();
});

test('the wake endpoint reports at-capacity as success, not failure', function () {
    // The app must not retry or error when every slot is already busy — the
    // work is going to be drained by a running slot either way.
    Bus::fake();
    $site = pumpSite(['enabled' => true, 'max_concurrency' => 1]);
    app(ServerlessQueuePump::class)->tryOpenSlot($site);

    $this->withHeader('X-Dply-Secret', 'test-command-secret')
        ->postJson(route('hooks.functions.queue.wake', ['site' => $site]))
        ->assertStatus(202)
        ->assertJson(['woken' => false, 'slots_opened' => 0, 'active_slots' => 1]);
});

test('the wake endpoint is a no-op when queue processing is disabled', function () {
    Bus::fake();
    $site = pumpSite(['enabled' => false]);

    $this->withHeader('X-Dply-Secret', 'test-command-secret')
        ->postJson(route('hooks.functions.queue.wake', ['site' => $site]))
        ->assertStatus(202)
        ->assertJson(['woken' => false]);

    Bus::assertNothingDispatched();
});

test('the exact ping the handler sends is accepted by the wake endpoint', function () {
    // Closes the loop between the two halves: the handler POSTs an empty JSON
    // body with the command secret in X-Dply-Secret and nothing else. If the
    // endpoint ever required more than that, every deployed function would
    // silently lose its low-latency queue path.
    Bus::fake();
    $site = pumpSite();

    $this->call(
        'POST',
        route('hooks.functions.queue.wake', ['site' => $site]),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DPLY_SECRET' => 'test-command-secret',
        ],
        '{}',
    )->assertStatus(202);

    Bus::assertDispatched(ServerlessQueueSlotJob::class);
});

test('a slot mirrors reported failures into serverless_failed_jobs', function () {
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport([
        'processed' => 2,
        'failed' => 1,
        'remaining' => 0,
        'failures' => [[
            'uuid' => 'b3f1-uuid',
            'connection_name' => 'redis',
            'queue' => 'emails',
            'job_class' => 'App\\Jobs\\SendInvoice',
            'exception_message' => "Connection refused\nsecond line",
            'exception_excerpt' => 'RuntimeException: Connection refused in /var/task/app.php:12',
            'failed_at' => '2026-08-09T09:00:00+00:00',
        ]],
    ]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    $failed = ServerlessFailedJob::query()->where('site_id', $site->id)->sole();
    expect($failed->uuid)->toBe('b3f1-uuid');
    expect($failed->queue)->toBe('emails');
    expect($failed->shortJobClass())->toBe('SendInvoice');
    // The list row shows one line, not the whole message.
    expect($failed->headline())->toBe('Connection refused');
});

test('the same failure reported twice updates one row', function () {
    // Overlapping slots can both see a failure; duplicating it would make the
    // panel lie about how many jobs are actually broken.
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);

    $failure = [[
        'uuid' => 'same-uuid',
        'job_class' => 'App\\Jobs\\Thing',
        'exception_message' => 'first',
        'failed_at' => '2026-08-09T09:00:00+00:00',
    ]];

    $pump->tryOpenSlot($site);
    fakeSlotReport(['processed' => 1, 'failed' => 1, 'remaining' => 0, 'failures' => $failure]);
    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    $pump->tryOpenSlot($site);
    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect(ServerlessFailedJob::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('a failure with no uuid is still recorded', function () {
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport([
        'processed' => 0,
        'failed' => 1,
        'remaining' => 0,
        'failures' => [['job_class' => 'App\\Jobs\\Anon', 'exception_message' => 'boom']],
    ]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    $failed = ServerlessFailedJob::query()->where('site_id', $site->id)->sole();
    expect($failed->uuid)->toBeNull();
    expect($failed->shortJobClass())->toBe('Anon');
});

test('failures are recorded even when the slot then stops', function () {
    // The mirror runs before the continue/stop decision — a slot that drains
    // the queue and closes must still have recorded what failed.
    Bus::fake();
    $site = pumpSite();
    $pump = app(ServerlessQueuePump::class);
    $pump->tryOpenSlot($site);

    fakeSlotReport([
        'processed' => 1,
        'failed' => 1,
        'remaining' => 0,
        'failures' => [['uuid' => 'u1', 'job_class' => 'App\\Jobs\\Last', 'exception_message' => 'boom']],
    ]);

    (new ServerlessQueueSlotJob($site->id))->handle($pump, app(InvokeFunctionTick::class));

    expect($pump->activeSlots($site))->toBe(0);
    expect(ServerlessFailedJob::query()->where('site_id', $site->id)->count())->toBe(1);
});
