<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for the dashboard "Observability" section — the metrics
 * graphs + window selector and the runtime-log viewer added to the
 * container dashboard. Exercises the ManagesContainerSite trait
 * methods refreshContainerMetrics / setContainerMetricsWindow /
 * fetchContainerRuntimeLogs.
 */
class CloudObservabilityDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_observability_section_renders_on_dashboard(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->scaffoldSite('digitalocean_app_platform', withCredential: false);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Observability')
            ->assertSee('Runtime logs')
            ->assertSee('Fetch runtime logs');
    }

    public function test_refresh_metrics_renders_graphs_for_do_site(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => [[
                    'metric' => ['app_component' => 'web'],
                    'values' => [[1700000000, '30'], [1700000060, '55']],
                ]]],
            ], 200),
        ]);
        [$user, $server, $site] = $this->scaffoldSite('digitalocean_app_platform', backendId: 'do-app-1');

        $component = Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertSet('container_metrics_result', null)
            ->call('refreshContainerMetrics')
            ->assertHasNoErrors();

        $result = $component->get('container_metrics_result');
        $this->assertIsArray($result);
        $this->assertTrue($result['available']);
        // The chart component renders an SVG polyline for the series.
        $component->assertSee('CPU')->assertSee('Memory');
    }

    public function test_window_selector_switches_window_and_refetches(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => [[
                    'metric' => ['app_component' => 'web'],
                    'values' => [[1700000000, '1']],
                ]]],
            ], 200),
        ]);
        [$user, $server, $site] = $this->scaffoldSite('digitalocean_app_platform', backendId: 'do-app-1');

        $component = Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('setContainerMetricsWindow', '24h')
            ->assertSet('container_metrics_window', '24h')
            ->assertHasNoErrors();

        $result = $component->get('container_metrics_result');
        $this->assertSame('24h', $result['window']);
    }

    public function test_app_runner_site_renders_cloudwatch_fallback(): void
    {
        [$user, $server, $site] = $this->scaffoldSite('aws_app_runner', backendId: 'arn:aws:apprunner:us-east-1:1:service/edge/x');

        $component = Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('refreshContainerMetrics')
            ->assertHasNoErrors();

        $result = $component->get('container_metrics_result');
        $this->assertFalse($result['available']);
        $component->assertSee('Metrics unavailable')
            ->assertSee('View in CloudWatch');
    }

    public function test_fetch_runtime_logs_populates_lines_via_fake_backend(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->scaffoldSite('digitalocean_app_platform', withCredential: false);

        $component = Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertSet('container_runtime_logs_result', null)
            ->call('fetchContainerRuntimeLogs')
            ->assertHasNoErrors();

        $result = $component->get('container_runtime_logs_result');
        $this->assertIsArray($result);
        $this->assertTrue($result['available']);
        $this->assertNotEmpty($result['lines']);
        $component->assertSee('fake-edge');
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function scaffoldSite(string $backend, ?string $backendId = null, bool $withCredential = true): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        // A real credential routes CloudRouter to the concrete backend.
        // Fake-cloud tests pass withCredential:false so the router falls
        // through to FakeCloudBackend (it only does so when no real
        // credential is persisted for the org).
        if ($withCredential) {
            ProviderCredential::query()->create([
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'provider' => $backend,
                'name' => 'cred',
                'credentials' => $backend === 'aws_app_runner'
                    ? ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1']
                    : ['api_token' => 't'],
            ]);
        }

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'API service',
            'slug' => 'api-service',
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

        return [$user, $server, $site];
    }
}
