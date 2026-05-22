<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AttachCloudDatabaseJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: CloudDatabase, 1: Site}
     */
    private function databaseAndSite(string $envFile = '', ?CloudDatabase $db = null): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $db ??= CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

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
            'env_file_content' => $envFile,
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);

        return [$db, $site];
    }

    public function test_attach_injects_db_env_and_queues_redeploy(): void
    {
        Bus::fake([RedeployCloudSiteJob::class]);
        [$db, $site] = $this->databaseAndSite("APP_ENV=production\nAPP_DEBUG=false");

        (new AttachCloudDatabaseJob($db->id, $site->id))->handle();

        $env = $site->fresh()->env_file_content;
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
        $this->assertStringContainsString('DB_HOST=db.example.ondigitalocean.com', $env);
        $this->assertStringContainsString('DB_PASSWORD=secret-pass', $env);

        $this->assertDatabaseHas('cloud_database_site', [
            'cloud_database_id' => $db->id,
            'site_id' => $site->id,
        ]);

        Bus::assertDispatched(RedeployCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_attach_overwrites_existing_db_keys(): void
    {
        Bus::fake([RedeployCloudSiteJob::class]);
        [$db, $site] = $this->databaseAndSite("DB_HOST=old-host\nDB_PORT=1234");

        (new AttachCloudDatabaseJob($db->id, $site->id))->handle();

        $env = $site->fresh()->env_file_content;
        $this->assertStringContainsString('DB_HOST=db.example.ondigitalocean.com', $env);
        $this->assertStringNotContainsString('old-host', $env);
    }

    public function test_detach_removes_db_env_and_queues_redeploy(): void
    {
        Bus::fake([RedeployCloudSiteJob::class]);
        [$db, $site] = $this->databaseAndSite();

        (new AttachCloudDatabaseJob($db->id, $site->id))->handle();
        (new AttachCloudDatabaseJob($db->id, $site->id, detach: true))->handle();

        $env = $site->fresh()->env_file_content;
        $this->assertStringNotContainsString('DB_HOST', $env);
        $this->assertStringNotContainsString('DB_PASSWORD', $env);

        $this->assertDatabaseMissing('cloud_database_site', [
            'cloud_database_id' => $db->id,
            'site_id' => $site->id,
        ]);

        Bus::assertDispatched(RedeployCloudSiteJob::class);
    }

    public function test_detach_preserves_unrelated_env_keys(): void
    {
        Bus::fake([RedeployCloudSiteJob::class]);
        [$db, $site] = $this->databaseAndSite("APP_ENV=production");

        (new AttachCloudDatabaseJob($db->id, $site->id))->handle();
        (new AttachCloudDatabaseJob($db->id, $site->id, detach: true))->handle();

        $this->assertStringContainsString('APP_ENV=production', $site->fresh()->env_file_content);
    }

    public function test_redis_attach_injects_redis_env(): void
    {
        Bus::fake([RedeployCloudSiteJob::class]);
        $redis = CloudDatabase::factory()->redis()->active()->create();
        [$db, $site] = $this->databaseAndSite('', $redis);

        (new AttachCloudDatabaseJob($db->id, $site->id))->handle();

        $env = $site->fresh()->env_file_content;
        $this->assertStringContainsString('REDIS_HOST=db.example.ondigitalocean.com', $env);
        $this->assertStringNotContainsString('DB_CONNECTION', $env);
    }

    public function test_missing_records_are_a_no_op(): void
    {
        Bus::fake();
        (new AttachCloudDatabaseJob('01nope0000000000000000nope', '01nope0000000000000000nope'))->handle();
        Bus::assertNothingDispatched();
    }
}
