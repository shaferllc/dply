<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessErrorStreamTest;

use App\Models\ErrorEvent;
use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Support\Errors\ErrorEventSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A broken serverless function has no ConsoleAction and no SiteDeployment
 * behind it, so until the syncer grew a third arm it was invisible to the
 * Errors tab, `dply errors`, and site-error notifications — visible only via
 * `dply serverless errors`. These lock in the arm and, just as importantly,
 * the fold: a function failing on every request must not mint an event per
 * request.
 *
 * @param  array<string, mixed>  $attrs
 */
function invocation(Site $site, array $attrs = []): FunctionInvocation
{
    return FunctionInvocation::query()->create(array_merge([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_WEB,
        'state' => FunctionInvocation::STATE_COMPLETED,
        'method' => 'GET',
        'path' => '/checkout',
        'status_code' => 500,
        'success' => false,
        'duration_ms' => 120,
        'cold' => false,
        'log_lines' => [],
        'result_excerpt' => 'Undefined method App\\Order::total()',
        'created_at' => now(),
    ], $attrs));
}

function sync(): int
{
    return app(ErrorEventSyncer::class)->sync(now()->subMinutes(15), notify: false);
}

test('a failed invocation lands in the site error stream', function () {
    $site = Site::factory()->create();
    invocation($site);

    expect(sync())->toBe(1);

    $event = ErrorEvent::query()->where('site_id', $site->id)->sole();

    expect($event->category)->toBe('function_invocation');
    expect($event->title)->toContain('500');
    expect($event->detail)->toContain('/checkout');
    expect($event->detail)->toContain('Undefined method');
    // A function has no box, so it must not roll up into a server's stream.
    expect($event->server_id)->toBeNull();
});

test('a failing streak folds to one open event', function () {
    $site = Site::factory()->create();
    invocation($site);
    sync();

    invocation($site, ['path' => '/cart']);
    invocation($site, ['path' => '/pay']);

    expect(sync())->toBe(0);
    expect(ErrorEvent::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('an in-flight async invocation is not an error yet', function () {
    $site = Site::factory()->create();
    // success defaults to false on insert, so a pending row looks like a
    // failure to anything that does not check state.
    invocation($site, ['state' => FunctionInvocation::STATE_PENDING, 'status_code' => null]);

    expect(sync())->toBe(0);
    expect(ErrorEvent::query()->count())->toBe(0);
});

test('recovery closes the open event so the next outage opens a fresh one', function () {
    $site = Site::factory()->create();
    invocation($site);
    sync();

    invocation($site, ['success' => true, 'status_code' => 200, 'result_excerpt' => null]);
    sync();

    expect(ErrorEvent::query()->whereNull('dismissed_at')->count())->toBe(0);

    invocation($site, ['path' => '/checkout-again']);

    expect(sync())->toBe(1);
    expect(ErrorEvent::query()->whereNull('dismissed_at')->count())->toBe(1);
});

test('a successful function never opens an event', function () {
    $site = Site::factory()->create();
    invocation($site, ['success' => true, 'status_code' => 200]);

    expect(sync())->toBe(0);
    expect(ErrorEvent::query()->count())->toBe(0);
});
