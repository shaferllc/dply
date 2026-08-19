<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Support\Servers\WorkerHostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('worker host context is empty for application servers', function (): void {
    $server = new Server(['meta' => ['server_role' => 'application']]);

    $context = WorkerHostContext::for($server);

    expect($context->isWorkerHost)->toBeFalse()
        ->and($context->isSiteSourced)->toBeFalse()
        ->and($context->originSite)->toBeNull()
        ->and($context->manageUrl)->toBeNull();
});

test('worker host context resolves the origin site from the pool', function (): void {
    $origin = Site::factory()->create(['name' => 'Origin app']);
    $pool = WorkerPool::factory()->create([
        'organization_id' => $origin->organization_id,
        'source_server_id' => $origin->server_id,
        'meta' => ['origin_site_id' => (string) $origin->id],
    ]);
    $worker = Server::factory()->create([
        'organization_id' => $origin->organization_id,
        'user_id' => $origin->user_id,
        'worker_pool_id' => $pool->id,
        'meta' => ['server_role' => 'worker', 'site_sourced_fleet' => true],
    ]);

    $context = WorkerHostContext::for($worker);

    expect($context->isWorkerHost)->toBeTrue()
        ->and($context->isSiteSourced)->toBeTrue()
        ->and($context->originSite?->is($origin))->toBeTrue()
        ->and($context->manageUrl)->toBe(route('sites.show', [
            'server' => $origin->server_id,
            'site' => $origin,
            'section' => 'worker-fleet',
        ]));
});
