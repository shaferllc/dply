<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\FunctionScheduleServiceTest;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Services\FunctionScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function functionSite(bool $provisioned = true): Site
{
    $credential = ProviderCredential::factory()->create([
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'tok-123'],
    ]);

    $server = Server::factory()->create([
        'provider_credential_id' => $provisioned ? $credential->id : null,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => $provisioned
                ? ['namespace' => 'fn-test', 'api_host' => 'https://faas.example', 'access_key' => 'id:secret']
                : [],
        ],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
    ]);
}
test('it lists scheduled triggers', function () {
    Http::fake([
        'api.digitalocean.com/v2/functions/namespaces/*/triggers' => Http::response([
            'triggers' => [['name' => 'dply-hourly', 'scheduled_details' => ['cron' => '0 * * * *']]],
        ], 200),
    ]);

    $result = app(FunctionScheduleService::class)->list(functionSite());

    expect($result['ok'])->toBeTrue();
    expect($result['triggers'])->toHaveCount(1);
});
test('it creates a scheduled trigger bound to the function', function () {
    Http::fake(['api.digitalocean.com/*' => Http::response(['trigger' => ['name' => 'dply-hourly']], 200)]);

    $result = app(FunctionScheduleService::class)->add(functionSite(), 'dply-hourly', '0 * * * *');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/functions/namespaces/fn-test/triggers')
        && $request['type'] === 'SCHEDULED'
        && $request['function'] === 'laravel-demo'
        && data_get($request->data(), 'scheduled_details.cron') === '0 * * * *');
});
test('it removes a scheduled trigger', function () {
    Http::fake(['api.digitalocean.com/*' => Http::response(null, 204)]);

    $result = app(FunctionScheduleService::class)->remove(functionSite(), 'dply-hourly');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/triggers/dply-hourly'));
});
test('an unprovisioned host returns an error', function () {
    Http::fake();

    $result = app(FunctionScheduleService::class)->list(functionSite(provisioned: false));

    expect($result['ok'])->toBeFalse();
    expect($result['triggers'])->toBe([]);
    Http::assertNothingSent();
});
test('preset and custom trigger names are stable', function () {
    $service = new FunctionScheduleService;

    expect($service->presetTriggerName('hourly'))->toBe('dply-hourly');
    expect($service->customTriggerName('0 9 * * 1-5'))->toBe($service->customTriggerName('0 9 * * 1-5'));
});
