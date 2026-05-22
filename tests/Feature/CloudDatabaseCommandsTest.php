<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\ProvisionCloudDatabaseJob;
use App\Jobs\TeardownCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CloudDatabaseCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function orgWithDoCredential(): Organization
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'DO',
            'credentials' => ['api_token' => 'tok'],
        ]);

        return $org;
    }

    private function containerSite(Organization $org): Site
    {
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);
    }

    // --- create ---------------------------------------------------------

    public function test_create_command_creates_database_and_queues_job(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();

        $exit = Artisan::call('dply:cloud:db:create', [
            '--name' => 'acme-db',
            '--engine' => 'postgres',
            '--size' => 'medium',
            '--org' => $org->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('cloud_databases', ['name' => 'acme-db', 'engine' => 'postgres', 'size' => 'medium']);
        Bus::assertDispatched(ProvisionCloudDatabaseJob::class);
    }

    public function test_create_command_fails_on_unknown_engine(): void
    {
        $org = $this->orgWithDoCredential();

        $exit = Artisan::call('dply:cloud:db:create', [
            '--name' => 'x',
            '--engine' => 'oracle',
            '--org' => $org->id,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown engine', Artisan::output());
    }

    public function test_create_command_fails_without_credential(): void
    {
        $org = Organization::factory()->create();

        $exit = Artisan::call('dply:cloud:db:create', [
            '--name' => 'x',
            '--engine' => 'postgres',
            '--org' => $org->id,
        ]);

        $this->assertSame(1, $exit);
    }

    // --- list -----------------------------------------------------------

    public function test_list_command_json(): void
    {
        $org = $this->orgWithDoCredential();
        CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
        CloudDatabase::factory()->mysql()->create(['organization_id' => $org->id, 'name' => 'my-one']);

        $exit = Artisan::call('dply:cloud:db:list', ['--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(2, $payload['total']);
    }

    public function test_list_command_filters_by_engine(): void
    {
        $org = $this->orgWithDoCredential();
        CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
        CloudDatabase::factory()->mysql()->create(['organization_id' => $org->id, 'name' => 'my-one']);

        Artisan::call('dply:cloud:db:list', ['--json' => true, '--engine' => 'mysql']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('my-one', $payload['databases'][0]['name']);
    }

    public function test_list_command_rejects_unknown_status(): void
    {
        $exit = Artisan::call('dply:cloud:db:list', ['--status' => 'bogus']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown --status', Artisan::output());
    }

    public function test_list_command_empty_message(): void
    {
        $exit = Artisan::call('dply:cloud:db:list');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No managed databases found', Artisan::output());
    }

    // --- attach / detach ------------------------------------------------

    public function test_attach_command_queues_job(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();
        $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
        $site = $this->containerSite($org);

        $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

        $this->assertSame(0, $exit);
        Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($j) => ! $j->detach && $j->siteId === $site->id);
    }

    public function test_attach_command_rejects_inactive_database(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();
        $db = CloudDatabase::factory()->create(['organization_id' => $org->id]); // provisioning
        $site = $this->containerSite($org);

        $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

        $this->assertSame(1, $exit);
        Bus::assertNotDispatched(AttachCloudDatabaseJob::class);
    }

    public function test_attach_command_rejects_non_container_site(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();
        $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
        $server = Server::factory()->ready()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Php]);

        $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

        $this->assertSame(1, $exit);
    }

    public function test_detach_command_queues_job_in_detach_mode(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();
        $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
        $site = $this->containerSite($org);

        $exit = Artisan::call('dply:cloud:db:detach', ['database' => $db->name, 'site' => $site->id]);

        $this->assertSame(0, $exit);
        Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($j) => $j->detach === true);
    }

    public function test_attach_command_fails_on_unknown_database(): void
    {
        Bus::fake();
        $exit = Artisan::call('dply:cloud:db:attach', ['database' => 'nope', 'site' => 'nope']);
        $this->assertSame(1, $exit);
    }

    // --- teardown -------------------------------------------------------

    public function test_teardown_command_queues_job(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();
        $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

        $exit = Artisan::call('dply:cloud:db:teardown', ['database' => $db->id]);

        $this->assertSame(0, $exit);
        Bus::assertDispatched(TeardownCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id);
    }

    public function test_teardown_command_fails_on_unknown_database(): void
    {
        Bus::fake();
        $exit = Artisan::call('dply:cloud:db:teardown', ['database' => 'nope']);
        $this->assertSame(1, $exit);
        Bus::assertNotDispatched(TeardownCloudDatabaseJob::class);
    }
}
