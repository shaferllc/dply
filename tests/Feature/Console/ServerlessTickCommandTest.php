<?php

declare(strict_types=1);

namespace Tests\Feature\Console\ServerlessTickCommandTest;
use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $serverless
 */
function functionSite(string $status, array $serverless): Site
{
    $server = Server::factory()->create([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas.example',
                'access_key' => 'id:secret',
                'namespace' => 'fn-test',
            ],
        ],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'status' => $status,
        'meta' => ['serverless' => array_merge(['action_name' => 'laravel-demo'], $serverless)],
    ]);
}
function fakeActivation(): void
{
    Http::fake([
        'https://faas.example/api/v1/namespaces/_/actions/*' => Http::response([
            'activationId' => 'act-1',
            'duration' => 12,
            'annotations' => [],
            'logs' => [],
            'response' => [
                'status' => 'success',
                'success' => true,
                'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'ticked'],
            ],
        ], 200),
    ]);
}
test('it ticks enabled active functions via the authenticated api', function () {
    fakeActivation();

    $site = functionSite(Site::STATUS_FUNCTIONS_ACTIVE, ['background_enabled' => true]);

    $this->artisan('serverless:tick')->assertSuccessful();

    // One schedule + one queue tick, both recorded as source=tick rows.
    expect(FunctionInvocation::query()->where('site_id', $site->id)->count())->toBe(2);
    expect(FunctionInvocation::query()->where('site_id', $site->id)->where('task', 'schedule')->count())->toBe(1);
    expect(FunctionInvocation::query()->where('site_id', $site->id)->where('task', 'queue')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/actions/laravel-demo')
        && data_get($request->data(), '__ow_headers.x-dply-run') === 'schedule'
        && data_get($request->data(), '__ow_headers.x-dply-secret') === $site->fresh()->ensureServerlessCommandSecret());
});
test('it skips functions without background enabled', function () {
    Http::fake();

    functionSite(Site::STATUS_FUNCTIONS_ACTIVE, []);

    $this->artisan('serverless:tick')->assertSuccessful();

    Http::assertNothingSent();
    expect(FunctionInvocation::query()->count())->toBe(0);
});
test('it skips functions that are not yet live', function () {
    Http::fake();

    functionSite(Site::STATUS_FUNCTIONS_CONFIGURED, ['background_enabled' => true]);

    $this->artisan('serverless:tick')->assertSuccessful();

    Http::assertNothingSent();
});
test('keep warm ticks the function without a command header', function () {
    fakeActivation();

    $site = functionSite(Site::STATUS_FUNCTIONS_ACTIVE, ['keep_warm' => true]);

    $this->artisan('serverless:tick')->assertSuccessful();

    expect(FunctionInvocation::query()
        ->where('site_id', $site->id)->where('task', 'keep-warm')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/actions/laravel-demo')
        && data_get($request->data(), '__ow_headers.x-dply-run') === null);
});
