<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessUsageCollectorTest;

use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\ServerlessUsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function managedFunctionSite(int $memoryMb = 512, string $backend = Site::SERVERLESS_BACKEND_DPLY): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'serverless_backend' => $backend,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'action_name' => 'demo',
                'limits' => ['memory' => $memoryMb],
            ],
        ],
    ]);
}

function recordInvocation(Site $site, int $durationMs, string $source = FunctionInvocation::SOURCE_WEB, ?string $actionId = null): void
{
    FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'function_action_id' => $actionId,
        'source' => $source,
        'method' => 'GET',
        'path' => '/',
        'status_code' => 200,
        'success' => true,
        'duration_ms' => $durationMs,
        'created_at' => now(),
    ]);
}

function collect(bool $dryRun = false): array
{
    return app(ServerlessUsageCollector::class)->collectForDate(now()->startOfDay(), $dryRun);
}

test('gib-seconds are metered from duration and the site memory limit', function () {
    $site = managedFunctionSite(memoryMb: 512);

    // 4 x 500ms at 512MB = 2 seconds x 0.5 GiB = 1 GiB-second.
    foreach (range(1, 4) as $ignored) {
        recordInvocation($site, 500);
    }

    $result = collect();

    expect($result['invocations'])->toBe(4);
    expect($result['gib_seconds'])->toBe(1);

    $snapshot = ServerlessUsageSnapshot::query()->where('site_id', $site->id)->sole();
    expect($snapshot->invocations)->toBe(4);
    expect($snapshot->gib_seconds)->toBe(1);
});

test('memory comes from the function action when the invocation is linked to one', function () {
    $site = managedFunctionSite(memoryMb: 128);

    $action = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'demo',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'php:8.4',
        'entrypoint' => 'main',
        'memory_mb' => 1024,
        'timeout_ms' => 30_000,
        'concurrency' => 1,
    ]);

    // 10s at 1GiB = 10 GiB-seconds — the action's memory wins over the
    // site limit, so an upsized function is not metered at the old size.
    recordInvocation($site, 10_000, actionId: $action->id);

    expect(collect()['gib_seconds'])->toBe(10);
});

test('an action with no recorded memory falls back to the site limit rather than metering as free', function () {
    $site = managedFunctionSite(memoryMb: 1024);

    $action = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'legacy',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'php:8.4',
        'entrypoint' => 'main',
        'memory_mb' => 0,
        'timeout_ms' => 30_000,
        'concurrency' => 1,
    ]);

    recordInvocation($site, 2_000, actionId: $action->id);

    expect(collect()['gib_seconds'])->toBe(2);
});

test('platform tick burn is metered but stays attributable in the snapshot meta', function () {
    $site = managedFunctionSite(memoryMb: 512);

    recordInvocation($site, 1_000, FunctionInvocation::SOURCE_WEB);
    recordInvocation($site, 3_000, FunctionInvocation::SOURCE_TICK);

    expect(collect()['gib_seconds'])->toBe(2);

    $snapshot = ServerlessUsageSnapshot::query()->where('site_id', $site->id)->sole();
    expect($snapshot->meta['by_source']['web']['gib_seconds'])->toBe(1);
    expect($snapshot->meta['by_source']['tick']['gib_seconds'])->toBe(2);
});

test('byo functions are not metered — their provider bills the customer directly', function () {
    $site = managedFunctionSite(memoryMb: 512, backend: 'byo');

    recordInvocation($site, 10_000);

    expect(collect()['gib_seconds'])->toBe(0);
    expect(ServerlessUsageSnapshot::query()->count())->toBe(0);
});

test('a dry run reports usage without writing snapshots', function () {
    $site = managedFunctionSite(memoryMb: 512);
    recordInvocation($site, 2_000);

    expect(collect(dryRun: true)['gib_seconds'])->toBe(1);
    expect(ServerlessUsageSnapshot::query()->count())->toBe(0);
});
