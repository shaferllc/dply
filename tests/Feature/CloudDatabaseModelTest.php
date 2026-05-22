<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudDatabaseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_provisioning_postgres_database(): void
    {
        $db = CloudDatabase::factory()->create();

        $this->assertSame(CloudDatabase::ENGINE_POSTGRES, $db->engine);
        $this->assertSame(CloudDatabase::STATUS_PROVISIONING, $db->status);
        $this->assertSame(CloudDatabase::BACKEND_DIGITALOCEAN, $db->backend);
        $this->assertFalse($db->isActive());
    }

    public function test_connection_is_encrypted_at_rest(): void
    {
        $db = CloudDatabase::factory()->active()->create();

        $raw = \DB::table('cloud_databases')->where('id', $db->id)->value('connection');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-pass', $raw);

        $this->assertSame('secret-pass', $db->fresh()->connection['password']);
    }

    public function test_size_tier_maps_to_do_size_slug(): void
    {
        $this->assertSame('db-s-1vcpu-1gb', CloudDatabase::factory()->make(['size' => 'small'])->backendSizeSlug());
        $this->assertSame('db-s-1vcpu-2gb', CloudDatabase::factory()->make(['size' => 'medium'])->backendSizeSlug());
        $this->assertSame('db-s-2vcpu-4gb', CloudDatabase::factory()->make(['size' => 'large'])->backendSizeSlug());
        $this->assertSame('db-s-1vcpu-1gb', CloudDatabase::factory()->make(['size' => 'bogus'])->backendSizeSlug());
    }

    public function test_engine_maps_to_do_engine_slug(): void
    {
        $this->assertSame('pg', CloudDatabase::factory()->make(['engine' => 'postgres'])->backendEngineSlug());
        $this->assertSame('mysql', CloudDatabase::factory()->make(['engine' => 'mysql'])->backendEngineSlug());
        $this->assertSame('redis', CloudDatabase::factory()->make(['engine' => 'redis'])->backendEngineSlug());
    }

    public function test_postgres_connection_env_vars(): void
    {
        $db = CloudDatabase::factory()->active()->create();

        $this->assertSame([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.example.ondigitalocean.com',
            'DB_PORT' => '25060',
            'DB_DATABASE' => 'defaultdb',
            'DB_USERNAME' => 'doadmin',
            'DB_PASSWORD' => 'secret-pass',
        ], $db->connectionEnvVars());
    }

    public function test_mysql_connection_env_vars_use_mysql_driver(): void
    {
        $db = CloudDatabase::factory()->mysql()->active()->create();

        $this->assertSame('mysql', $db->connectionEnvVars()['DB_CONNECTION']);
    }

    public function test_redis_connection_env_vars(): void
    {
        $db = CloudDatabase::factory()->redis()->active()->create();

        $this->assertSame([
            'REDIS_HOST' => 'db.example.ondigitalocean.com',
            'REDIS_PORT' => '25060',
            'REDIS_PASSWORD' => 'secret-pass',
        ], $db->connectionEnvVars());
    }

    public function test_connection_env_vars_empty_when_not_provisioned(): void
    {
        $db = CloudDatabase::factory()->create();

        $this->assertSame([], $db->connectionEnvVars());
    }

    public function test_connection_env_keys_per_engine(): void
    {
        $this->assertContains('DB_HOST', CloudDatabase::factory()->make()->connectionEnvKeys());
        $this->assertContains('REDIS_HOST', CloudDatabase::factory()->redis()->make()->connectionEnvKeys());
    }

    public function test_sites_relation_via_pivot(): void
    {
        $db = CloudDatabase::factory()->create();
        $org = Organization::factory()->create();
        $site = Site::factory()->create(['organization_id' => $org->id]);

        $db->sites()->attach($site->id);

        $this->assertTrue($db->fresh()->sites->contains($site->id));
        $this->assertDatabaseHas('cloud_database_site', [
            'cloud_database_id' => $db->id,
            'site_id' => $site->id,
        ]);
    }
}
