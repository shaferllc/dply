<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\PollEdgeStatusJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollEdgeStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_phase_transitions_status_and_records_url(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([
                'app' => [
                    'id' => 'app-12345',
                    'phase' => 'ACTIVE',
                    'default_ingress' => 'https://acme.ondigitalocean.app',
                    'spec' => ['name' => 'acme'],
                ],
            ], 200),
        ]);

        $site = $this->makeProvisioningSite();

        (new PollEdgeStatusJob($site->id))->handle();

        $fresh = $site->fresh();
        $this->assertSame(Site::STATUS_CONTAINER_ACTIVE, $fresh->status);
        $this->assertSame('https://acme.ondigitalocean.app', $fresh->meta['container']['live_url']);
        $this->assertSame('ACTIVE', $fresh->meta['container']['last_phase']);
    }

    public function test_error_phase_transitions_to_failed(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([
                'app' => ['id' => 'app-12345', 'phase' => 'ERROR'],
            ], 200),
        ]);
        $site = $this->makeProvisioningSite();

        (new PollEdgeStatusJob($site->id))->handle();

        $this->assertSame(Site::STATUS_CONTAINER_FAILED, $site->fresh()->status);
    }

    public function test_intermediate_phase_keeps_provisioning(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([
                'app' => ['id' => 'app-12345', 'phase' => 'BUILDING'],
            ], 200),
        ]);
        $site = $this->makeProvisioningSite();

        (new PollEdgeStatusJob($site->id))->handle();

        $this->assertSame(Site::STATUS_CONTAINER_PROVISIONING, $site->fresh()->status);
        $this->assertSame('BUILDING', $site->fresh()->meta['container']['last_phase']);
    }

    public function test_unknown_phase_does_not_change_status(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([
                'app' => ['id' => 'app-12345', 'phase' => 'SOMETHING_NEW'],
            ], 200),
        ]);
        $site = $this->makeProvisioningSite();

        (new PollEdgeStatusJob($site->id))->handle();

        $this->assertSame(Site::STATUS_CONTAINER_PROVISIONING, $site->fresh()->status);
    }

    public function test_backend_inspect_failure_records_error_without_status_change(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345' => Http::response([], 503),
        ]);
        $site = $this->makeProvisioningSite();

        (new PollEdgeStatusJob($site->id))->handle();

        $fresh = $site->fresh();
        $this->assertSame(Site::STATUS_CONTAINER_PROVISIONING, $fresh->status);
        $this->assertNotEmpty($fresh->meta['container']['last_poll_error']);
    }

    public function test_non_container_site_is_no_op(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
        ]);
        $originalStatus = $site->status;

        (new PollEdgeStatusJob($site->id))->handle();

        $this->assertSame($originalStatus, $site->fresh()->status);
    }

    private function makeProvisioningSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_backend_id' => 'app-12345',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_PROVISIONING,
        ]);
    }
}
