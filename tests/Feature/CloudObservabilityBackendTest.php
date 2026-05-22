<?php

declare(strict_types=1);

namespace Tests\Feature\CloudObservabilityBackendTest;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\Cloud\FakeCloudBackend;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('do backend returns normalized metric series', function () {
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/cpu_percentage*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'matrix',
                'result' => [[
                    'metric' => ['app_component' => 'web'],
                    'values' => [[1700000000, '12.5'], [1700000060, '18.0']],
                ]],
            ],
        ], 200),
        'api.digitalocean.com/v2/monitoring/metrics/apps/memory_percentage*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '40.0']],
            ]]],
        ], 200),
        'api.digitalocean.com/v2/monitoring/metrics/apps/restart_count*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '0']],
            ]]],
        ], 200),
    ]);

    [$site, $credential] = doSite();
    $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '6h');

    expect($result['available'])->toBeTrue();
    expect($result['window'])->toBe('6h');
    expect($result['series']['cpu'])->toHaveCount(2);
    expect($result['series']['cpu'][0]['t'])->toBe(1700000000);
    expect($result['series']['cpu'][0]['v'])->toBe(12.5);
    expect($result['series']['memory'])->toHaveCount(1);
    expect($result['series']['restarts'])->toHaveCount(1);
});
test('do backend degrades to unavailable on api error', function () {
    Http::fake([
        'api.digitalocean.com/*' => Http::response('boom', 500),
    ]);

    [$site, $credential] = doSite();
    $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '1h');

    expect($result['available'])->toBeFalse();
    expect($result)->toHaveKey('note');
});
test('do backend metrics without backend id is unavailable', function () {
    [$site, $credential] = doSite(backendId: null);
    $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '1h');

    expect($result['available'])->toBeFalse();
});
test('do backend fetches and tails runtime logs', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/components/web/logs*' => Http::response([
            'historic_urls' => ['https://logs.example/run.log'],
        ], 200),
        'logs.example/run.log' => Http::response("line one\nline two\nline three", 200),
    ]);

    [$site, $credential] = doSite();
    $result = (new DigitalOceanAppPlatformBackend)->runtimeLogs($site, $credential, 2);

    expect($result['available'])->toBeTrue();

    // Tailed to the last 2 lines.
    expect($result['lines'])->toBe(['line two', 'line three']);
    expect($result['url'])->toBe('https://logs.example/run.log');
});
test('do backend metrics are cached for the window', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '1']],
            ]]],
        ], 200),
    ]);

    [$site, $credential] = doSite();
    $backend = new DigitalOceanAppPlatformBackend;
    $backend->metrics($site, $credential, '1h');
    $backend->metrics($site, $credential, '1h');

    // 3 metric calls on the first invocation, none on the cached second.
    Http::assertSentCount(3);
});
test('aws backend metrics return cloudwatch fallback', function () {
    [$site, $credential] = awsSite();
    $result = (new AwsAppRunnerBackend)->metrics($site, $credential, '24h');

    expect($result['available'])->toBeFalse();
    expect($result['window'])->toBe('24h');
    expect($result)->toHaveKey('url');
    $this->assertStringContainsString('cloudwatch', $result['url']);
    $this->assertStringContainsString('CloudWatch', $result['note']);
});
test('aws backend runtime logs return cloudwatch link', function () {
    [$site, $credential] = awsSite();
    $result = (new AwsAppRunnerBackend)->runtimeLogs($site, $credential);

    expect($result['available'])->toBeFalse();
    expect($result['lines'])->toBe([]);
    $this->assertStringContainsString('cloudwatch', $result['url']);
    $this->assertStringContainsString('apprunner', $result['note']);
});
test('fake backend returns deterministic metrics', function () {
    [$site, $credential] = doSite();
    $backend = new FakeCloudBackend('digitalocean_app_platform');

    $a = $backend->metrics($site, $credential, '1h');
    $b = $backend->metrics($site, $credential, '1h');

    expect($a['available'])->toBeTrue();
    expect($a['series']['cpu'])->not->toBeEmpty();
    expect($a['series']['memory'])->not->toBeEmpty();
    expect($a['series'])->toHaveKey('restarts');

    // Deterministic — same site yields identical series.
    expect($b['series']['cpu'])->toBe($a['series']['cpu']);
});
test('fake backend returns synthetic runtime logs', function () {
    [$site, $credential] = doSite();
    $result = (new FakeCloudBackend)->runtimeLogs($site, $credential);

    expect($result['available'])->toBeTrue();
    expect($result['lines'])->not->toBeEmpty();
    $this->assertStringContainsString('fake-edge', $result['lines'][0]);
});
test('window codes normalize to supported values', function () {
    [$site, $credential] = doSite();

    // An unknown window code falls back to 1h.
    $result = (new FakeCloudBackend)->metrics($site, $credential, 'bogus');
    expect($result['window'])->toBe('1h');
});
/**
 * @return array{0: Site, 1: ProviderCredential}
 */
function doSite(?string $backendId = 'do-app-1'): array
{
    return makeSite('digitalocean_app_platform', $backendId, ['api_token' => 't']);
}
/**
 * @return array{0: Site, 1: ProviderCredential}
 */
function awsSite(): array
{
    return makeSite('aws_app_runner', 'arn:aws:apprunner:us-east-1:1:service/edge/x', [
        'access_key_id' => 'k',
        'secret_access_key' => 's',
        'region' => 'us-east-1',
    ]);
}
/**
 * @param  array<string, string>  $credentials
 * @return array{0: Site, 1: ProviderCredential}
 */
function makeSite(string $backend, ?string $backendId, array $credentials): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'slug' => 'edge-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'container_backend_id' => $backendId,
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => $backend,
        'name' => 'cred',
        'credentials' => $credentials,
    ]);

    return [$site, $credential];
}
