<?php

declare(strict_types=1);

namespace Tests\Feature\Console\ServerlessQueueDoctorCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function doctorSite(string $env, array $serverlessOverrides = []): Site
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
        'env_file_content' => $env,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => array_merge([
                'command_secret' => 'test-command-secret',
                'action_name' => 'demo',
                'action_url' => 'https://faas.example/api/v1/web/fn-test/default/demo',
                'queue' => ['enabled' => true],
            ], $serverlessOverrides),
        ],
    ]);
}

const HEALTHY_ENV = "QUEUE_CONNECTION=redis\nDPLY_COMMAND_SECRET=test-command-secret\nDPLY_QUEUE_WAKE_URL=https://dply.example/hooks/functions/x/queue/wake\n";

beforeEach(function () {
    Cache::flush();
});

test('a healthy function reports no problems', function () {
    $site = doctorSite(HEALTHY_ENV);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->assertSuccessful();
});

test('it flags an unset QUEUE_CONNECTION as a problem', function () {
    // The silent failure this command exists to catch.
    $site = doctorSite("DPLY_COMMAND_SECRET=test-command-secret\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('QUEUE_CONNECTION is not set')
        ->assertFailed();
});

test('it flags a sync QUEUE_CONNECTION as a problem', function () {
    $site = doctorSite("QUEUE_CONNECTION=sync\nDPLY_COMMAND_SECRET=test-command-secret\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('nothing is ever queued')
        ->assertFailed();
});

test('it flags a function that has never deployed', function () {
    $site = doctorSite(HEALTHY_ENV, ['action_url' => '']);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('never deployed')
        ->assertFailed();
});

test('it flags background processing being off', function () {
    $site = doctorSite(HEALTHY_ENV, ['queue' => ['enabled' => false]]);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('Background processing is off')
        ->assertFailed();
});

test('it flags a missing command secret', function () {
    $site = doctorSite("QUEUE_CONNECTION=redis\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('No DPLY_COMMAND_SECRET')
        ->assertFailed();
});

test('a missing wake url is a note, not a problem', function () {
    // Draining still works via the safety-net tick — it is just slower. That
    // is a degraded state worth naming, not a failure.
    $site = doctorSite("QUEUE_CONNECTION=redis\nDPLY_COMMAND_SECRET=test-command-secret\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('falls back to the one-minute tick')
        ->assertSuccessful();
});

test('the database queue on a networked database gets a jobs-table note', function () {
    $site = doctorSite("QUEUE_CONNECTION=database\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=test-command-secret\nDPLY_QUEUE_WAKE_URL=https://dply.example/x\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('jobs` table')
        ->assertSuccessful();
});

test('the database queue on sqlite is a hard problem, not a note', function () {
    // /tmp is per-container, so concurrent drains each get their own SQLite
    // file. Jobs are not delayed by this — they are silently dropped.
    $site = doctorSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\nDPLY_COMMAND_SECRET=test-command-secret\nDPLY_QUEUE_WAKE_URL=https://dply.example/x\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('enqueued jobs are lost')
        ->assertFailed();
});

test('json output carries the machine-readable report', function () {
    $site = doctorSite(HEALTHY_ENV);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id, '--json' => true])
        ->assertSuccessful();
});

test('the probe reports what the function actually said', function () {
    Http::fake([
        'faas.example/*' => Http::response([
            'activationId' => 'act-1',
            'duration' => 40,
            'annotations' => [],
            'logs' => [],
            'response' => [
                'success' => true,
                'result' => [
                    'statusCode' => 200,
                    'headers' => [],
                    'body' => (string) json_encode([
                        'dply_queue_slot' => true,
                        'ok' => true,
                        'processed' => 3,
                        'failed' => 0,
                        'failures' => [],
                        'remaining' => 7,
                        'duration_ms' => 900,
                    ]),
                ],
            ],
        ]),
    ]);

    $site = doctorSite(HEALTHY_ENV);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id, '--probe' => true])
        ->expectsOutputToContain('Live probe')
        ->assertSuccessful();
});

test('the probe flags a handler deployed before the pump', function () {
    // Plain-text body — the pre-pump handler. The pump can still drive it,
    // but it cannot report queue depth, so this is worth naming explicitly.
    Http::fake([
        'faas.example/*' => Http::response([
            'activationId' => 'act-1',
            'duration' => 40,
            'annotations' => [],
            'logs' => [],
            'response' => [
                'success' => true,
                'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'dply ran queue:work — exit 0'],
            ],
        ]),
    ]);

    $site = doctorSite(HEALTHY_ENV);

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id, '--probe' => true])
        ->expectsOutputToContain('unconfirmed')
        ->assertSuccessful();
});

test('it refuses a non-serverless site', function () {
    $site = Site::factory()->create();

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->assertFailed();
});

test('it reports an unknown site cleanly', function () {
    $this->artisan('dply:serverless:queue-doctor', ['site' => 'nope'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});

test('it flags a per-container cache store as a silent WithoutOverlapping trap', function () {
    // ShouldBeUnique / WithoutOverlapping / RateLimited are backed by the
    // CACHE, not the queue. With a per-container store they do nothing at all
    // while appearing to work — a worse bug than the one dply Queue fixes,
    // because it looks healthy.
    $site = doctorSite("QUEUE_CONNECTION=redis\nCACHE_STORE=array\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('silently do nothing')
        ->assertSuccessful();
});

test('an unset cache store is flagged too, since the handler defaults it to array', function () {
    $site = doctorSite("QUEUE_CONNECTION=redis\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('per-container on a function')
        ->assertSuccessful();
});

test('a shared cache store is not flagged', function () {
    $site = doctorSite("QUEUE_CONNECTION=redis\nCACHE_STORE=redis\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->doesntExpectOutputToContain('silently do nothing')
        ->assertSuccessful();
});

test('it flags failed jobs vanishing into a per-container SQLite file', function () {
    $site = doctorSite("QUEUE_CONNECTION=redis\nCACHE_STORE=redis\nDB_CONNECTION=sqlite\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('disappear without a trace')
        ->assertSuccessful();
});

test('a networked database is not flagged for failed jobs', function () {
    $site = doctorSite("QUEUE_CONNECTION=redis\nCACHE_STORE=redis\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->doesntExpectOutputToContain('disappear without a trace')
        ->assertSuccessful();
});

test('the dply queue connection passes the backend check', function () {
    $site = doctorSite("QUEUE_CONNECTION=dply\nCACHE_STORE=redis\nDB_CONNECTION=pgsql\nDPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->assertSuccessful();
});

test('dply Queue closes the cache and failed-job gaps rather than reporting them', function () {
    // The handler registers a shared lock store and a server-side failed-job
    // provider when a namespace is wired, so neither warning applies — even
    // though the cache store and database still look per-container.
    $site = doctorSite("QUEUE_CONNECTION=dply\nCACHE_STORE=array\nDB_CONNECTION=sqlite\n"
        ."DPLY_QUEUE_URL=https://queue.dply.test/api/queue/v1/ns\nDPLY_QUEUE_SECRET=s3cret\n"
        ."DPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->doesntExpectOutputToContain('silently do nothing')
        ->doesntExpectOutputToContain('disappear without a trace')
        ->assertSuccessful();
});

test('the gaps are still reported when dply Queue is not wired', function () {
    // Same per-container cache and SQLite, but no namespace — both warn.
    $site = doctorSite("QUEUE_CONNECTION=redis\nCACHE_STORE=array\nDB_CONNECTION=sqlite\n"
        ."DPLY_COMMAND_SECRET=s\nDPLY_QUEUE_WAKE_URL=https://x/y\n");

    $this->artisan('dply:serverless:queue-doctor', ['site' => $site->id])
        ->expectsOutputToContain('silently do nothing')
        ->expectsOutputToContain('disappear without a trace')
        ->assertSuccessful();
});
