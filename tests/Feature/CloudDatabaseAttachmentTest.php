<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CloudDatabaseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_available_databases_to_attach(): void
    {
        [$user, $server, $site, $org] = $this->makeContainerSite();
        CloudDatabase::factory()->active()->create([
            'organization_id' => $org->id,
            'name' => 'attachable-db',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertSee('Managed databases')
            ->assertSee('attachable-db');
    }

    public function test_dashboard_lists_attached_database_with_detach_control(): void
    {
        [$user, $server, $site, $org] = $this->makeContainerSite();
        $database = CloudDatabase::factory()->active()->create([
            'organization_id' => $org->id,
            'name' => 'attached-db',
        ]);
        $database->sites()->attach($site->id);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertSee('attached-db')
            ->assertSee('Detach');
    }

    public function test_attach_dispatches_job(): void
    {
        Queue::fake();
        [$user, $server, $site, $org] = $this->makeContainerSite();
        $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_database_attach_id', $database->id)
            ->call('attachContainerDatabase');

        Queue::assertPushed(AttachCloudDatabaseJob::class, function (AttachCloudDatabaseJob $job) use ($database, $site): bool {
            return $job->cloudDatabaseId === $database->id
                && $job->siteId === $site->id
                && $job->detach === false;
        });
    }

    public function test_attach_with_no_selection_shows_toast(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_database_attach_id', '')
            ->call('attachContainerDatabase')
            ->assertDispatched('notify');

        Queue::assertNotPushed(AttachCloudDatabaseJob::class);
    }

    public function test_attach_rejects_database_from_another_org(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();
        $otherOrg = Organization::factory()->create();
        $database = CloudDatabase::factory()->active()->create(['organization_id' => $otherOrg->id]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_database_attach_id', $database->id)
            ->call('attachContainerDatabase')
            ->assertDispatched('notify');

        Queue::assertNotPushed(AttachCloudDatabaseJob::class);
    }

    public function test_attach_rejects_database_that_is_not_active(): void
    {
        Queue::fake();
        [$user, $server, $site, $org] = $this->makeContainerSite();
        $database = CloudDatabase::factory()->create([
            'organization_id' => $org->id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_database_attach_id', $database->id)
            ->call('attachContainerDatabase')
            ->assertDispatched('notify');

        Queue::assertNotPushed(AttachCloudDatabaseJob::class);
    }

    public function test_detach_dispatches_job_with_detach_flag(): void
    {
        Queue::fake();
        [$user, $server, $site, $org] = $this->makeContainerSite();
        $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
        $database->sites()->attach($site->id);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('detachContainerDatabase', $database->id);

        Queue::assertPushed(AttachCloudDatabaseJob::class, function (AttachCloudDatabaseJob $job) use ($database, $site): bool {
            return $job->cloudDatabaseId === $database->id
                && $job->siteId === $site->id
                && $job->detach === true;
        });
    }

    /**
     * @return array{0: User, 1: Server, 2: Site, 3: Organization}
     */
    private function makeContainerSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $server = Server::factory()->ready()->create([
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
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);

        return [$user, $server, $site, $org];
    }
}
