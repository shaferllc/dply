<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanAppPlatformServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_app_posts_spec_and_returns_id(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => [
                    'id' => 'app-12345',
                    'default_ingress' => 'https://api-acme.ondigitalocean.app',
                ],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $result = $service->createApp(
            appName: 'api-acme',
            region: 'nyc',
            image: 'ghcr.io/acme/api:v1.2.3',
            port: 8080,
            envVars: ['APP_ENV' => 'production'],
        );

        $this->assertSame('app-12345', $result['id']);
        $this->assertSame('https://api-acme.ondigitalocean.app', $result['default_ingress']);

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
    }

    public function test_get_app_returns_app_details(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([
                'app' => ['id' => 'app-12345', 'phase' => 'ACTIVE'],
            ], 200),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $app = $service->getApp('app-12345');

        $this->assertSame('ACTIVE', $app['phase']);
    }

    public function test_deploy_app_creates_deployment(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345/deployments' => Http::response([
                'deployment' => ['id' => 'dep-99999'],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $result = $service->deployApp('app-12345', force: true);

        $this->assertSame('dep-99999', $result['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['force_build'] === true);
    }

    public function test_delete_app_calls_delete_endpoint(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([], 204),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $service->deleteApp('app-12345');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v2/apps/app-12345'));
    }

    public function test_validate_token_throws_on_401(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('validate token');
        $service->validateToken();
    }

    public function test_constructor_rejects_empty_token(): void
    {
        $cred = $this->credential();
        $cred->credentials = ['api_token' => ''];

        $this->expectException(\InvalidArgumentException::class);
        new DigitalOceanAppPlatformService($cred);
    }

    public function test_parse_image_ref_handles_common_shapes(): void
    {
        $service = new DigitalOceanAppPlatformService($this->credential());

        [$registry, $repo, $tag] = $service->parseImageRef('ghcr.io/acme/api:v1.2.3');
        $this->assertSame(['ghcr.io', 'acme/api', 'v1.2.3'], [$registry, $repo, $tag]);

        [$registry, $repo, $tag] = $service->parseImageRef('nginx:1.27');
        $this->assertSame(['docker.io', 'library/nginx', '1.27'], [$registry, $repo, $tag]);

        [$registry, $repo, $tag] = $service->parseImageRef('acme/api');
        $this->assertSame(['docker.io', 'acme/api', 'latest'], [$registry, $repo, $tag]);
    }

    public function test_get_regions_returns_known_set(): void
    {
        $regions = DigitalOceanAppPlatformService::getRegions();

        $slugs = array_column($regions, 'slug');
        $this->assertContains('nyc', $slugs);
        $this->assertContains('fra', $slugs);
        $this->assertContains('sgp', $slugs);
    }

    public function test_create_app_throws_on_api_error(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response(['message' => 'invalid spec'], 422),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('create app');
        $service->createApp('api-acme', 'nyc', 'nginx:1', 80);
    }

    public function test_create_app_from_source_posts_github_spec(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-src-1', 'default_ingress' => null],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
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

        $this->assertSame('app-src-1', $result['id']);

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
    }

    public function test_create_app_from_source_omits_dockerfile_path_when_blank(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-src-2', 'default_ingress' => null],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
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
    }

    private function credential(): ProviderCredential
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
}
