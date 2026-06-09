<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\FunctionInvokerTest;

use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\FunctionInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function functionSite(bool $provisioned = true): Site
{
    $functionsMeta = $provisioned
        ? ['api_host' => 'https://faas.example', 'access_key' => 'id:secret', 'namespace' => 'fn-test']
        : [];

    $server = Server::factory()->create([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => $functionsMeta,
        ],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
    ]);
}
test('it records a successful activation with logs and cold start', function () {
    Http::fake([
        'https://faas.example/api/v1/namespaces/_/actions/*' => Http::response([
            'activationId' => 'act-99',
            'duration' => 128,
            'annotations' => [['key' => 'initTime', 'value' => 240]],
            'logs' => ['production.INFO: warmed up'],
            'response' => [
                'status' => 'success',
                'success' => true,
                'result' => ['statusCode' => 201, 'headers' => [], 'body' => 'created'],
            ],
        ], 200),
    ]);

    $site = functionSite();

    $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TEST, null, [
        '__ow_method' => 'GET',
        '__ow_path' => '',
    ]);

    expect($result['ok'])->toBeTrue();
    $invocation = $result['invocation'];
    expect($invocation)->not->toBeNull();
    expect($invocation->source)->toBe('test');
    expect($invocation->activation_id)->toBe('act-99');
    expect($invocation->status_code)->toBe(201);
    expect($invocation->duration_ms)->toBe(128);
    expect($invocation->cold)->toBeTrue();
    expect($invocation->logLines())->toBe(['production.INFO: warmed up']);
});
test('an unprovisioned host fails without recording a row', function () {
    $site = functionSite(provisioned: false);

    $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TICK, 'schedule', []);

    expect($result['ok'])->toBeFalse();
    expect($result['invocation'])->toBeNull();
    $this->assertDatabaseCount('function_invocations', 0);
});
test('a transport failure still records a failed row', function () {
    Http::fake([
        'https://faas.example/*' => fn () => throw new \RuntimeException('connection timed out'),
    ]);

    $site = functionSite();

    $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TICK, 'queue', []);

    expect($result['ok'])->toBeFalse();
    expect($result['invocation'])->not->toBeNull();
    expect($result['invocation']->success)->toBeFalse();
    $this->assertDatabaseHas('function_invocations', [
        'site_id' => $site->id,
        'source' => 'tick',
        'task' => 'queue',
        'success' => false,
        'activation_id' => null,
    ]);
});
