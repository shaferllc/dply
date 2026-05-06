<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\DigitalOceanAppPlatformService;
use App\Services\Edge\DigitalOceanAppPlatformBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Build-time env vars: stored under meta.container.build_env_file_content,
 * pushed as DO scope=BUILD_TIME (or App Runner BuildEnvironmentVariables)
 * by the backend adapters. Exposed via:
 *   - dashboard editor (second textarea on container-dashboard)
 *   - dply:edge:env --build flag
 *   - dply:edge:doctor json (env.build_set)
 */
class EdgeBuildEnvTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_service_create_app_includes_build_time_envs(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-1', 'default_ingress' => null],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $service->createApp(
            appName: 'svc',
            region: 'nyc',
            image: 'nginx:1',
            port: 80,
            envVars: ['APP_ENV' => 'production'],
            buildEnvVars: ['NPM_TOKEN' => 'ghp_xxx'],
        );

        Http::assertSent(function (Request $request) {
            $envs = $request->data()['spec']['services'][0]['envs'] ?? [];
            $byKey = [];
            foreach ($envs as $entry) {
                $byKey[$entry['key']] = $entry['scope'];
            }

            return ($byKey['APP_ENV'] ?? null) === 'RUN_TIME'
                && ($byKey['NPM_TOKEN'] ?? null) === 'BUILD_TIME';
        });
    }

    public function test_do_backend_provision_pushes_build_envs_from_meta(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-1', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'env_file_content' => "APP_ENV=production\n",
            'meta' => ['container' => ['build_env_file_content' => "NPM_TOKEN=ghp_xxx\n"]],
        ]);

        $cred = $this->credential();
        (new DigitalOceanAppPlatformBackend)->provision($site, $cred);

        Http::assertSent(function (Request $request) {
            $envs = $request->data()['spec']['services'][0]['envs'] ?? [];
            $byKey = [];
            foreach ($envs as $entry) {
                $byKey[$entry['key']] = $entry['scope'];
            }

            return ($byKey['APP_ENV'] ?? null) === 'RUN_TIME'
                && ($byKey['NPM_TOKEN'] ?? null) === 'BUILD_TIME';
        });
    }

    public function test_dashboard_save_persists_build_env(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->scaffoldSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_env_file_input', "APP_ENV=production\n")
            ->set('container_build_env_file_input', "NPM_TOKEN=ghp_xxx\n")
            ->call('saveContainerEnvAndRedeploy')
            ->assertHasNoErrors();

        $fresh = $site->fresh();
        $this->assertStringContainsString('APP_ENV=production', $fresh->env_file_content);
        $this->assertStringContainsString('NPM_TOKEN=ghp_xxx', $fresh->meta['container']['build_env_file_content']);
        Queue::assertPushed(RedeployEdgeSiteJob::class);
    }

    public function test_cli_build_flag_targets_build_env_storage(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        [, , $site] = $this->scaffoldSite();

        $exit = Artisan::call('dply:edge:env', [
            'site' => $site->name,
            '--set' => ['NPM_TOKEN=ghp_xxx'],
            '--build' => true,
            '--no-redeploy' => true,
        ]);

        $this->assertSame(0, $exit);
        $fresh = $site->fresh();
        $this->assertStringContainsString('NPM_TOKEN=ghp_xxx', $fresh->meta['container']['build_env_file_content']);
        // Runtime env should not have been touched.
        $this->assertSame('', (string) $fresh->env_file_content);
    }

    public function test_cli_default_targets_runtime_env(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        [, , $site] = $this->scaffoldSite();

        Artisan::call('dply:edge:env', [
            'site' => $site->name,
            '--set' => ['APP_ENV=staging'],
            '--no-redeploy' => true,
        ]);

        $fresh = $site->fresh();
        $this->assertStringContainsString('APP_ENV=staging', $fresh->env_file_content);
        $this->assertEmpty($fresh->meta['container']['build_env_file_content'] ?? '');
    }

    public function test_doctor_reports_env_state(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        [, , $site] = $this->scaffoldSite();
        $site->update([
            'env_file_content' => "APP_ENV=production\n",
            'meta' => ['container' => ['build_env_file_content' => "NPM_TOKEN=ghp_xxx\n"]],
        ]);

        Artisan::call('dply:edge:doctor', ['site' => $site->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue($payload['env']['runtime_set']);
        $this->assertTrue($payload['env']['build_set']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(array $overrides = []): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create(array_merge([
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
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => 'fake-app-1',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ], $overrides));
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function scaffoldSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
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
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => 'fake-app-1',
            'env_file_content' => '',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
                ],
            ],
        ]);

        return [$user, $server, $site];
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
