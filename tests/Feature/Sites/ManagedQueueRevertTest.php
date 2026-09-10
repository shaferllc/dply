<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ManagedQueueRevertTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Queue\Models\QueueNamespace;
use App\Services\Sites\ManagedQueueConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
        'queue_service.entitlements.defaults' => [
            'available' => true,
            'max_namespaces' => 5,
            'max_queue_depth' => 0,
            'max_payload_bytes' => 262144,
            'requests_per_minute' => 600,
        ],
        'queue_service.entitlements.plans' => [],
    ]);

    $this->organization = Organization::factory()->create();
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id]);
});

function siteWithEnv(string $env): Site
{
    return Site::factory()->create([
        'organization_id' => test()->organization->id,
        'server_id' => test()->server->id,
        'env_file_content' => $env,
    ]);
}

function connector(): ManagedQueueConnector
{
    return app(ManagedQueueConnector::class);
}

test('connecting records the connection it replaces', function () {
    $site = siteWithEnv("APP_ENV=production\nQUEUE_CONNECTION=redis\n");

    connector()->connect($site);
    $site->refresh();

    expect(data_get($site->meta, 'managed_queue.previous.QUEUE_CONNECTION'))->toBe('redis')
        ->and($site->env_file_content)->toContain('QUEUE_CONNECTION=dply');
});

/**
 * The revert this whole path exists for: back on the site's own queue, with the
 * dply credential gone rather than left lying in the file.
 */
test('reverting restores the previous connection and removes the credential', function () {
    $site = siteWithEnv("APP_ENV=production\nQUEUE_CONNECTION=redis\n");

    connector()->connect($site);
    $site->refresh();

    connector()->disconnect($site);
    $site->refresh();

    expect($site->env_file_content)
        ->toContain('QUEUE_CONNECTION=redis')
        ->not->toContain('DPLY_QUEUE_TOKEN')
        ->not->toContain('DPLY_QUEUE_URL')
        ->not->toContain('QUEUE_FAILED_DRIVER');

    expect(data_get($site->meta, 'managed_queue'))->toBeNull();
});

/**
 * Undoing the connection is a different decision from destroying the jobs — the
 * namespace page owns the second one, with the depth in front of you.
 */
test('reverting keeps the namespace and everything in it', function () {
    $site = siteWithEnv("QUEUE_CONNECTION=redis\n");

    $namespace = connector()->connect($site)['namespace'];
    $site->refresh();

    connector()->disconnect($site);

    expect(QueueNamespace::query()->find($namespace->id))->not->toBeNull();
});

test('a site connected before the revert path existed still reverts sensibly', function () {
    // No `previous` recorded: the fallback reads what the site has attached,
    // and drops to sync only when it genuinely has nowhere else to run jobs.
    $site = siteWithEnv("QUEUE_CONNECTION=dply\nDPLY_QUEUE_TOKEN=secret\n");
    $site->forceFill(['meta' => ['managed_queue' => ['namespace_id' => 'x', 'connected_at' => now()->toIso8601String()]]])->save();

    connector()->disconnect($site);
    $site->refresh();

    expect($site->env_file_content)
        ->toContain('QUEUE_CONNECTION=sync')
        ->not->toContain('DPLY_QUEUE_TOKEN');
});

test('reverting clears the stale observation so the panel stops reporting dply', function () {
    $site = siteWithEnv("QUEUE_CONNECTION=redis\n");

    connector()->connect($site);
    $site->refresh();
    $site->forceFill(['meta' => array_merge((array) $site->meta, [
        'queue_observed' => ['connection' => 'dply', 'driver' => 'dply', 'observed_at' => now()->toIso8601String()],
    ])])->save();

    connector()->disconnect($site->refresh());

    expect(data_get($site->refresh()->meta, 'queue_observed'))->toBeNull();
});
