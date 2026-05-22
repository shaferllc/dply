<?php

declare(strict_types=1);

namespace Tests\Feature\AttachCloudDatabaseJobTest;
use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: CloudDatabase, 1: Site}
 */
function databaseAndSite(string $envFile = '', ?CloudDatabase $db = null): array
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
test('attach injects db env and queues redeploy', function () {
    Bus::fake([RedeployCloudSiteJob::class]);
    [$db, $site] = databaseAndSite("APP_ENV=production\nAPP_DEBUG=false");

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
});
test('attach overwrites existing db keys', function () {
    Bus::fake([RedeployCloudSiteJob::class]);
    [$db, $site] = databaseAndSite("DB_HOST=old-host\nDB_PORT=1234");

    (new AttachCloudDatabaseJob($db->id, $site->id))->handle();

    $env = $site->fresh()->env_file_content;
    $this->assertStringContainsString('DB_HOST=db.example.ondigitalocean.com', $env);
    $this->assertStringNotContainsString('old-host', $env);
});
test('detach removes db env and queues redeploy', function () {
    Bus::fake([RedeployCloudSiteJob::class]);
    [$db, $site] = databaseAndSite();

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
});
test('detach preserves unrelated env keys', function () {
    Bus::fake([RedeployCloudSiteJob::class]);
    [$db, $site] = databaseAndSite("APP_ENV=production");

    (new AttachCloudDatabaseJob($db->id, $site->id))->handle();
    (new AttachCloudDatabaseJob($db->id, $site->id, detach: true))->handle();

    $this->assertStringContainsString('APP_ENV=production', $site->fresh()->env_file_content);
});
test('redis attach injects redis env', function () {
    Bus::fake([RedeployCloudSiteJob::class]);
    $redis = CloudDatabase::factory()->redis()->active()->create();
    [$db, $site] = databaseAndSite('', $redis);

    (new AttachCloudDatabaseJob($db->id, $site->id))->handle();

    $env = $site->fresh()->env_file_content;
    $this->assertStringContainsString('REDIS_HOST=db.example.ondigitalocean.com', $env);
    $this->assertStringNotContainsString('DB_CONNECTION', $env);
});
test('missing records are a no op', function () {
    Bus::fake();
    (new AttachCloudDatabaseJob('01nope0000000000000000nope', '01nope0000000000000000nope'))->handle();
    Bus::assertNothingDispatched();
});
