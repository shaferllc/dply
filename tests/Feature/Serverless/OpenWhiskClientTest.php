<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\OpenWhiskClientTest;

use App\Models\Server;
use App\Modules\Serverless\Services\OpenWhiskClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function functionServer(bool $provisioned = true): Server
{
    return Server::factory()->create([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => $provisioned
                ? ['api_host' => 'https://faas.example', 'access_key' => 'id:secret', 'namespace' => 'fn-test']
                : [],
        ],
    ]);
}
test('an unprovisioned host makes no call and returns an error', function () {
    Http::fake();

    $result = (new OpenWhiskClient(functionServer(provisioned: false)))->actions();

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('not provisioned', (string) $result['error']);
    Http::assertNothingSent();
});
test('it lists actions', function () {
    Http::fake([
        'https://faas.example/api/v1/namespaces/_/actions*' => Http::response([
            ['name' => 'laravel-demo', 'version' => '0.0.11'],
        ], 200),
    ]);

    $result = (new OpenWhiskClient(functionServer()))->actions();

    expect($result['ok'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
});
test('it creates a trigger with parameters', function () {
    Http::fake(['https://faas.example/*' => Http::response(['name' => 'nightly'], 200)]);

    $result = (new OpenWhiskClient(functionServer()))->putTrigger('nightly', ['region' => 'nyc']);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/triggers/nightly')
        && str_contains($request->url(), 'overwrite=true')
        && data_get($request->data(), 'parameters.0.key') === 'region');
});
test('it creates a rule binding a trigger to an action', function () {
    Http::fake(['https://faas.example/*' => Http::response(['name' => 'r'], 200)]);

    $result = (new OpenWhiskClient(functionServer()))->putRule('r', 'nightly', 'laravel-demo');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/rules/r')
        && $request['trigger'] === 'nightly'
        && $request['action'] === 'laravel-demo');
});
test('it deletes an action', function () {
    Http::fake(['https://faas.example/*' => Http::response([], 200)]);

    $result = (new OpenWhiskClient(functionServer()))->deleteAction('laravel-demo');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/actions/laravel-demo'));
});
test('an http error surfaces as a failed result', function () {
    Http::fake(['https://faas.example/*' => Http::response(['error' => 'not found'], 404)]);

    $result = (new OpenWhiskClient(functionServer()))->action('missing');

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('HTTP 404', (string) $result['error']);
});
