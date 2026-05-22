<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionCloudDatabaseJobTest extends TestCase
{
    use RefreshDatabase;

    private function database(array $overrides = []): CloudDatabase
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'DO',
            'credentials' => ['api_token' => 'tok'],
        ]);

        return CloudDatabase::factory()->create(array_merge([
            'organization_id' => $org->id,
            'provider_credential_id' => $credential->id,
        ], $overrides));
    }

    public function test_creates_cluster_and_re_dispatches_while_still_provisioning(): void
    {
        Bus::fake();
        Http::fake([
            'https://api.digitalocean.com/v2/databases' => Http::response([
                'database' => [
                    'id' => 'do-db-1',
                    'status' => 'creating',
                    'engine' => 'pg',
                    'connection' => ['host' => '', 'port' => 0, 'user' => '', 'password' => '', 'database' => '', 'ssl' => true],
                ],
            ], 201),
        ]);

        $db = $this->database();
        (new ProvisionCloudDatabaseJob($db->id))->handle();

        $fresh = $db->fresh();
        $this->assertSame('do-db-1', $fresh->backend_id);
        $this->assertSame(CloudDatabase::STATUS_PROVISIONING, $fresh->status);

        Bus::assertDispatched(ProvisionCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id && $j->attempt === 2);
    }

    public function test_polls_existing_cluster_until_online_then_stores_connection(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/databases/do-db-9' => Http::response([
                'database' => [
                    'id' => 'do-db-9',
                    'status' => 'online',
                    'engine' => 'pg',
                    'connection' => [
                        'host' => 'db-9.ondigitalocean.com',
                        'port' => 25060,
                        'user' => 'doadmin',
                        'password' => 'sup3r secret',
                        'database' => 'defaultdb',
                        'ssl' => true,
                    ],
                ],
            ], 200),
        ]);

        $db = $this->database(['backend_id' => 'do-db-9', 'status' => CloudDatabase::STATUS_PROVISIONING]);
        (new ProvisionCloudDatabaseJob($db->id, 2))->handle();

        $fresh = $db->fresh();
        $this->assertSame(CloudDatabase::STATUS_ACTIVE, $fresh->status);
        $this->assertSame('db-9.ondigitalocean.com', $fresh->connection['host']);
        $this->assertSame('doadmin', $fresh->connection['username']);
        $this->assertSame('sup3r secret', $fresh->connection['password']);
        $this->assertSame('defaultdb', $fresh->connection['database']);
    }

    public function test_marks_failed_on_backend_error(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/databases' => Http::response(['message' => 'bad'], 422),
        ]);

        $db = $this->database();
        (new ProvisionCloudDatabaseJob($db->id))->handle();

        $fresh = $db->fresh();
        $this->assertSame(CloudDatabase::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->meta['error']);
    }

    public function test_marks_failed_without_a_credential(): void
    {
        $org = Organization::factory()->create();
        $db = CloudDatabase::factory()->create([
            'organization_id' => $org->id,
            'provider_credential_id' => null,
        ]);

        (new ProvisionCloudDatabaseJob($db->id))->handle();

        $this->assertSame(CloudDatabase::STATUS_FAILED, $db->fresh()->status);
    }

    public function test_missing_database_is_a_no_op(): void
    {
        (new ProvisionCloudDatabaseJob('01nope0000000000000000nope'))->handle();
        $this->assertTrue(true);
    }

    public function test_already_active_database_is_skipped(): void
    {
        Http::fake();
        $db = $this->database(['status' => CloudDatabase::STATUS_ACTIVE, 'backend_id' => 'do-db-x']);

        (new ProvisionCloudDatabaseJob($db->id))->handle();

        Http::assertNothingSent();
    }

    public function test_gives_up_after_max_attempts(): void
    {
        Bus::fake();
        Http::fake([
            'https://api.digitalocean.com/v2/databases/do-db-slow' => Http::response([
                'database' => [
                    'id' => 'do-db-slow',
                    'status' => 'creating',
                    'engine' => 'pg',
                    'connection' => ['host' => '', 'port' => 0, 'user' => '', 'password' => '', 'database' => '', 'ssl' => true],
                ],
            ], 200),
        ]);

        $db = $this->database(['backend_id' => 'do-db-slow', 'status' => CloudDatabase::STATUS_PROVISIONING]);
        (new ProvisionCloudDatabaseJob($db->id, 40))->handle();

        $this->assertSame(CloudDatabase::STATUS_FAILED, $db->fresh()->status);
        Bus::assertNotDispatched(ProvisionCloudDatabaseJob::class);
    }
}
