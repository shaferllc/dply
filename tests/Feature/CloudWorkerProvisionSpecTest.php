<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The DigitalOcean App Platform app spec must include `workers`
 * components — one per CloudWorker row — built from the same source
 * as the web service, on a fresh provision.
 */
class CloudWorkerProvisionSpecTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 'tok'],
        ]);

        return [$user, $org];
    }

    public function test_image_mode_provision_emits_worker_components(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'do-app-9', 'default_ingress' => 'https://x.ondigitalocean.app'],
            ], 201),
        ]);

        [$user, $org] = $this->scaffold();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v2',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_PENDING,
        ]);
        CloudWorker::factory()->create([
            'site_id' => $site->id,
            'command' => 'php artisan queue:work',
            'size' => 'large',
            'instance_count' => 2,
        ]);
        CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        (new ProvisionCloudSiteJob($site->id))->handle();

        Http::assertSent(function ($req) {
            $workers = $req->data()['spec']['workers'] ?? [];
            if (count($workers) !== 2) {
                return false;
            }
            foreach ($workers as $w) {
                // Workers share the web service's Docker image source.
                if (($w['image']['repository'] ?? null) !== 'acme/api') {
                    return false;
                }
            }
            $commands = array_column($workers, 'run_command');

            return in_array('php artisan queue:work', $commands, true)
                && in_array('php artisan schedule:work', $commands, true);
        });
    }

    public function test_source_mode_provision_emits_worker_components_with_github_source(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'do-app-src', 'default_ingress' => 'https://x.ondigitalocean.app'],
            ], 201),
        ]);

        [$user, $org] = $this->scaffold();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_PENDING,
            'meta' => ['container' => ['source' => [
                'repo' => 'acme/api',
                'branch' => 'main',
                'deploy_on_push' => true,
            ]]],
        ]);
        CloudWorker::factory()->create(['site_id' => $site->id]);

        (new ProvisionCloudSiteJob($site->id))->handle();

        Http::assertSent(function ($req) {
            $worker = $req->data()['spec']['workers'][0] ?? null;

            return $worker !== null
                && ($worker['github']['repo'] ?? null) === 'acme/api'
                && ($worker['github']['branch'] ?? null) === 'main';
        });
    }

    public function test_provision_emits_no_workers_key_when_site_has_none(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'do-app-0', 'default_ingress' => 'https://x.ondigitalocean.app'],
            ], 201),
        ]);

        [$user, $org] = $this->scaffold();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_PENDING,
        ]);

        (new ProvisionCloudSiteJob($site->id))->handle();

        Http::assertSent(fn ($req) => ! array_key_exists('workers', $req->data()['spec'] ?? []));
    }
}
