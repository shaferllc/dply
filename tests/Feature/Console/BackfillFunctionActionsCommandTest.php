<?php

declare(strict_types=1);

namespace Tests\Feature\Console\BackfillFunctionActionsCommandTest;
use App\Models\FunctionAction;
use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function functionsSite(array $serverlessConfig): Site
{
    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['serverless' => $serverlessConfig],
    ]);
}
function invocation(Site $site): FunctionInvocation
{
    return FunctionInvocation::query()->create([
        'site_id' => $site->id,
        'source' => 'web',
        'method' => 'GET',
        'path' => '/',
        'status_code' => 200,
        'success' => true,
        'duration_ms' => 10,
        'cold' => false,
        'created_at' => now(),
    ]);
}
test('it creates one code action per serverless site from meta', function () {
    $site = functionsSite([
        'action_name' => 'orders-api',
        'runtime' => 'php:8.3',
        'entrypoint' => 'main',
        'action_url' => 'https://faas.example.com/api/v1/web/ns/default/orders-api',
        'limits' => ['memory' => 256, 'timeout' => 30000, 'concurrency' => 1],
    ]);

    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

    $action = FunctionAction::query()->where('site_id', $site->id)->sole();
    expect($action->name)->toBe('orders-api');
    expect($action->kind)->toBe(FunctionAction::KIND_CODE);
    expect($action->runtime)->toBe('php:8.3');
    expect($action->entrypoint)->toBe('main');
    expect($action->memory_mb)->toBe(256);
    expect($action->timeout_ms)->toBe(30000);
    expect($action->url)->toBe('https://faas.example.com/api/v1/web/ns/default/orders-api');
});
test('it links existing invocations to the backfilled action', function () {
    $site = functionsSite(['action_name' => 'fn']);
    $a = invocation($site);
    $b = invocation($site);

    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

    $action = FunctionAction::query()->where('site_id', $site->id)->sole();
    expect($a->fresh()->function_action_id)->toBe($action->id);
    expect($b->fresh()->function_action_id)->toBe($action->id);
});
test('it is idempotent', function () {
    $site = functionsSite(['action_name' => 'fn']);

    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();
    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

    expect(FunctionAction::query()->where('site_id', $site->id)->count())->toBe(1);
});
test('dry run writes nothing', function () {
    $site = functionsSite(['action_name' => 'fn']);
    invocation($site);

    $this->artisan('serverless:backfill-function-actions', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(FunctionAction::query()->count())->toBe(0);
    expect(FunctionInvocation::query()->first()->function_action_id)->toBeNull();
});
test('it ignores non serverless sites', function () {
    Site::factory()->create();

    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

    expect(FunctionAction::query()->count())->toBe(0);
});
test('it falls back to the site slug when no action name is known', function () {
    $site = functionsSite([]);
    $site->update(['slug' => 'fallback-fn']);

    $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

    expect(FunctionAction::query()->where('site_id', $site->id)->sole()->name)->toBe('fallback-fn');
});
