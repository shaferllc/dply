<?php

declare(strict_types=1);

namespace Tests\Feature\DigitalOceanAppPlatformServiceTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('create app posts spec and returns id', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => [
                'id' => 'app-12345',
                'default_ingress' => 'https://api-acme.ondigitalocean.app',
            ],
        ], 201),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $result = $service->createApp(
        appName: 'api-acme',
        region: 'nyc',
        image: 'ghcr.io/acme/api:v1.2.3',
        port: 8080,
        envVars: ['APP_ENV' => 'production'],
    );

    expect($result['id'])->toBe('app-12345');
    expect($result['default_ingress'])->toBe('https://api-acme.ondigitalocean.app');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/apps')
            && $body['spec']['name'] === 'api-acme'
            && $body['spec']['region'] === 'nyc'
            && $body['spec']['services'][0]['http_port'] === 8080
            && $body['spec']['services'][0]['image']['repository'] === 'acme/api'
            && $body['spec']['services'][0]['image']['tag'] === 'v1.2.3'
            && $body['spec']['services'][0]['envs'][0]['key'] === 'APP_ENV';
    });
});
test('get app returns app details', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => ['id' => 'app-12345', 'phase' => 'ACTIVE'],
        ], 200),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $app = $service->getApp('app-12345');

    expect($app['phase'])->toBe('ACTIVE');
});
test('deploy app creates deployment', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345/deployments' => Http::response([
            'deployment' => ['id' => 'dep-99999'],
        ], 201),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $result = $service->deployApp('app-12345', force: true);

    expect($result['id'])->toBe('dep-99999');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->data()['force_build'] === true);
});
test('delete app calls delete endpoint', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([], 204),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $service->deleteApp('app-12345');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/v2/apps/app-12345'));
});
test('validate token throws on 401', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('validate token');
    $service->validateToken();
});
test('constructor rejects empty token', function () {
    $cred = credential();
    $cred->credentials = ['api_token' => ''];

    $this->expectException(\InvalidArgumentException::class);
    new DigitalOceanAppPlatformService($cred);
});
test('parse image ref handles common shapes', function () {
    $service = new DigitalOceanAppPlatformService(credential());

    [$registry, $repo, $tag] = $service->parseImageRef('ghcr.io/acme/api:v1.2.3');
    expect([$registry, $repo, $tag])->toBe(['ghcr.io', 'acme/api', 'v1.2.3']);

    [$registry, $repo, $tag] = $service->parseImageRef('nginx:1.27');
    expect([$registry, $repo, $tag])->toBe(['docker.io', 'library/nginx', '1.27']);

    [$registry, $repo, $tag] = $service->parseImageRef('acme/api');
    expect([$registry, $repo, $tag])->toBe(['docker.io', 'acme/api', 'latest']);
});
test('get regions returns known set', function () {
    $regions = DigitalOceanAppPlatformService::getRegions();

    $slugs = array_column($regions, 'slug');
    expect($slugs)->toContain('nyc');
    expect($slugs)->toContain('fra');
    expect($slugs)->toContain('sgp');
});
test('create app throws on api error', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response(['message' => 'invalid spec'], 422),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('create app');
    $service->createApp('api-acme', 'nyc', 'nginx:1', 80);
});
test('create app from source posts github spec', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-src-1', 'default_ingress' => null],
        ], 201),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $result = $service->createAppFromSource(
        appName: 'api-acme',
        region: 'nyc',
        repo: 'acme/api',
        branch: 'main',
        port: 8080,
        deployOnPush: true,
        dockerfilePath: 'docker/Dockerfile',
        envVars: ['APP_ENV' => 'production'],
    );

    expect($result['id'])->toBe('app-src-1');

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $svc = $body['spec']['services'][0];

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/apps')
            && ($svc['github']['repo'] ?? null) === 'acme/api'
            && ($svc['github']['branch'] ?? null) === 'main'
            && ($svc['github']['deploy_on_push'] ?? null) === true
            && ($svc['dockerfile_path'] ?? null) === 'docker/Dockerfile'
            && ($svc['http_port'] ?? null) === 8080
            && ($svc['envs'][0]['key'] ?? null) === 'APP_ENV';
    });
});
test('create app from source omits dockerfile path when blank', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-src-2', 'default_ingress' => null],
        ], 201),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $service->createAppFromSource(
        appName: 'svc',
        region: 'nyc',
        repo: 'acme/svc',
        branch: 'main',
        port: 3000,
    );

    Http::assertSent(function (Request $request) {
        $svc = $request->data()['spec']['services'][0];

        return ! array_key_exists('dockerfile_path', $svc);
    });
});
function credential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'Test',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
}
