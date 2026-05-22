<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\Cloud\FakeCloudBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Coverage for the metrics() + runtimeLogs() methods across all
 * three CloudBackend implementations:
 *   - DigitalOcean: real, against faked DO HTTP.
 *   - AWS App Runner: structured unavailable + CloudWatch link.
 *   - Fake: deterministic synthetic series / log lines.
 * Also covers the 60s cache wrapper on the DO backend.
 */
class CloudObservabilityBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_backend_returns_normalized_metric_series(): void
    {
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

        [$site, $credential] = $this->doSite();
        $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '6h');

        $this->assertTrue($result['available']);
        $this->assertSame('6h', $result['window']);
        $this->assertCount(2, $result['series']['cpu']);
        $this->assertSame(1700000000, $result['series']['cpu'][0]['t']);
        $this->assertSame(12.5, $result['series']['cpu'][0]['v']);
        $this->assertCount(1, $result['series']['memory']);
        $this->assertCount(1, $result['series']['restarts']);
    }

    public function test_do_backend_degrades_to_unavailable_on_api_error(): void
    {
        Http::fake([
            'api.digitalocean.com/*' => Http::response('boom', 500),
        ]);

        [$site, $credential] = $this->doSite();
        $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '1h');

        $this->assertFalse($result['available']);
        $this->assertArrayHasKey('note', $result);
    }

    public function test_do_backend_metrics_without_backend_id_is_unavailable(): void
    {
        [$site, $credential] = $this->doSite(backendId: null);
        $result = (new DigitalOceanAppPlatformBackend)->metrics($site, $credential, '1h');

        $this->assertFalse($result['available']);
    }

    public function test_do_backend_fetches_and_tails_runtime_logs(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/components/web/logs*' => Http::response([
                'historic_urls' => ['https://logs.example/run.log'],
            ], 200),
            'logs.example/run.log' => Http::response("line one\nline two\nline three", 200),
        ]);

        [$site, $credential] = $this->doSite();
        $result = (new DigitalOceanAppPlatformBackend)->runtimeLogs($site, $credential, 2);

        $this->assertTrue($result['available']);
        // Tailed to the last 2 lines.
        $this->assertSame(['line two', 'line three'], $result['lines']);
        $this->assertSame('https://logs.example/run.log', $result['url']);
    }

    public function test_do_backend_metrics_are_cached_for_the_window(): void
    {
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

        [$site, $credential] = $this->doSite();
        $backend = new DigitalOceanAppPlatformBackend;
        $backend->metrics($site, $credential, '1h');
        $backend->metrics($site, $credential, '1h');

        // 3 metric calls on the first invocation, none on the cached second.
        Http::assertSentCount(3);
    }

    public function test_aws_backend_metrics_return_cloudwatch_fallback(): void
    {
        [$site, $credential] = $this->awsSite();
        $result = (new AwsAppRunnerBackend)->metrics($site, $credential, '24h');

        $this->assertFalse($result['available']);
        $this->assertSame('24h', $result['window']);
        $this->assertArrayHasKey('url', $result);
        $this->assertStringContainsString('cloudwatch', $result['url']);
        $this->assertStringContainsString('CloudWatch', $result['note']);
    }

    public function test_aws_backend_runtime_logs_return_cloudwatch_link(): void
    {
        [$site, $credential] = $this->awsSite();
        $result = (new AwsAppRunnerBackend)->runtimeLogs($site, $credential);

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['lines']);
        $this->assertStringContainsString('cloudwatch', $result['url']);
        $this->assertStringContainsString('apprunner', $result['note']);
    }

    public function test_fake_backend_returns_deterministic_metrics(): void
    {
        [$site, $credential] = $this->doSite();
        $backend = new FakeCloudBackend('digitalocean_app_platform');

        $a = $backend->metrics($site, $credential, '1h');
        $b = $backend->metrics($site, $credential, '1h');

        $this->assertTrue($a['available']);
        $this->assertNotEmpty($a['series']['cpu']);
        $this->assertNotEmpty($a['series']['memory']);
        $this->assertArrayHasKey('restarts', $a['series']);
        // Deterministic — same site yields identical series.
        $this->assertSame($a['series']['cpu'], $b['series']['cpu']);
    }

    public function test_fake_backend_returns_synthetic_runtime_logs(): void
    {
        [$site, $credential] = $this->doSite();
        $result = (new FakeCloudBackend)->runtimeLogs($site, $credential);

        $this->assertTrue($result['available']);
        $this->assertNotEmpty($result['lines']);
        $this->assertStringContainsString('fake-edge', $result['lines'][0]);
    }

    public function test_window_codes_normalize_to_supported_values(): void
    {
        [$site, $credential] = $this->doSite();
        // An unknown window code falls back to 1h.
        $result = (new FakeCloudBackend)->metrics($site, $credential, 'bogus');
        $this->assertSame('1h', $result['window']);
    }

    /**
     * @return array{0: Site, 1: ProviderCredential}
     */
    private function doSite(?string $backendId = 'do-app-1'): array
    {
        return $this->makeSite('digitalocean_app_platform', $backendId, ['api_token' => 't']);
    }

    /**
     * @return array{0: Site, 1: ProviderCredential}
     */
    private function awsSite(): array
    {
        return $this->makeSite('aws_app_runner', 'arn:aws:apprunner:us-east-1:1:service/edge/x', [
            'access_key_id' => 'k',
            'secret_access_key' => 's',
            'region' => 'us-east-1',
        ]);
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array{0: Site, 1: ProviderCredential}
     */
    private function makeSite(string $backend, ?string $backendId, array $credentials): array
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
}
