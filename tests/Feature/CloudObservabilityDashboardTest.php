<?php

declare(strict_types=1);

namespace Tests\Feature\CloudObservabilityDashboardTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('observability section renders on dashboard', function () {
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = scaffoldSite('digitalocean_app_platform', withCredential: false);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Observability')
        ->assertSee('Runtime logs')
        ->assertSee('Fetch runtime logs');
});
test('refresh metrics renders graphs for do site', function () {
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '30'], [1700000060, '55']],
            ]]],
        ], 200),
    ]);
    [$user, $server, $site] = scaffoldSite('digitalocean_app_platform', backendId: 'do-app-1');

    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSet('container_metrics_result', null)
        ->call('refreshContainerMetrics')
        ->assertHasNoErrors();

    $result = $component->get('container_metrics_result');
    expect($result)->toBeArray();
    expect($result['available'])->toBeTrue();

    // The chart component renders an SVG polyline for the series.
    $component->assertSee('CPU')->assertSee('Memory');
});
test('window selector switches window and refetches', function () {
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '1']],
            ]]],
        ], 200),
    ]);
    [$user, $server, $site] = scaffoldSite('digitalocean_app_platform', backendId: 'do-app-1');

    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('setContainerMetricsWindow', '24h')
        ->assertSet('container_metrics_window', '24h')
        ->assertHasNoErrors();

    $result = $component->get('container_metrics_result');
    expect($result['window'])->toBe('24h');
});
test('app runner site renders cloudwatch fallback', function () {
    [$user, $server, $site] = scaffoldSite('aws_app_runner', backendId: 'arn:aws:apprunner:us-east-1:1:service/edge/x');

    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('refreshContainerMetrics')
        ->assertHasNoErrors();

    $result = $component->get('container_metrics_result');
    expect($result['available'])->toBeFalse();
    $component->assertSee('Metrics unavailable')
        ->assertSee('View in CloudWatch');
});
test('fetch runtime logs populates lines via fake backend', function () {
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = scaffoldSite('digitalocean_app_platform', withCredential: false);

    $component = Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSet('container_runtime_logs_result', null)
        ->call('fetchContainerRuntimeLogs')
        ->assertHasNoErrors();

    $result = $component->get('container_runtime_logs_result');
    expect($result)->toBeArray();
    expect($result['available'])->toBeTrue();
    expect($result['lines'])->not->toBeEmpty();
    $component->assertSee('fake-edge');
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function scaffoldSite(string $backend, ?string $backendId = null, bool $withCredential = true): array
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
            'provider' => CloudRouter::credentialProviderFor($backend),
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
