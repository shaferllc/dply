<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessTriggerProvisionerTest;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\ServerlessTriggerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function action(array $trigger): FunctionAction
{
    $server = Server::factory()->create([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas-nyc1.example.com',
                'access_key' => 'keyid:keysecret',
            ],
        ],
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    return FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'orders-api',
        'kind' => FunctionAction::KIND_CODE,
        'trigger' => $trigger,
    ]);
}
test('it provisions a trigger feed binding and rule', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $action = action(['cron' => '*/5 * * * *', 'enabled' => true]);

    $result = (new ServerlessTriggerProvisioner)->provision($action);

    expect($result['ok'])->toBeTrue();
    expect($result['trigger'])->toBe('orders-api-dply-cron');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/triggers/orders-api-dply-cron'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/actions/alarms/alarm')
        && $request['lifecycleEvent'] === 'CREATE'
        && $request['cron'] === '*/5 * * * *');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/rules/orders-api-dply-cron-rule')
        && $request['action'] === '/_/orders-api');
});
test('an action with no enabled schedule provisions nothing', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $action = action(['cron' => '*/5 * * * *', 'enabled' => false]);

    $result = (new ServerlessTriggerProvisioner)->provision($action);

    expect($result['ok'])->toBeTrue();
    expect($result['trigger'])->toBeNull();

    // Only teardown DELETEs may go out — never a trigger PUT.
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/triggers/'));
});
test('remove tears down the rule feed and trigger', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $action = action(['cron' => '0 * * * *', 'enabled' => true]);

    $result = (new ServerlessTriggerProvisioner)->remove($action);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/rules/orders-api-dply-cron-rule'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/actions/alarms/alarm')
        && $request['lifecycleEvent'] === 'DELETE');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/triggers/orders-api-dply-cron'));
});
