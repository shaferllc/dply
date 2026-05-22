<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TeardownCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeardownCloudDatabaseJobTest extends TestCase
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

        return CloudDatabase::factory()->active()->create(array_merge([
            'organization_id' => $org->id,
            'provider_credential_id' => $credential->id,
            'backend_id' => 'do-db-tear',
        ], $overrides));
    }

    public function test_deletes_cluster_and_removes_row(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(null, 204),
        ]);

        $db = $this->database();
        (new TeardownCloudDatabaseJob($db->id))->handle();

        $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
        Http::assertSent(fn ($req) => $req->method() === 'DELETE'
            && str_contains($req->url(), '/v2/databases/do-db-tear'));
    }

    public function test_is_idempotent_when_cluster_already_gone(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(['id' => 'not_found'], 404),
        ]);

        $db = $this->database();
        (new TeardownCloudDatabaseJob($db->id))->handle();

        $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
    }

    public function test_detaches_pivot_links_before_delete(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(null, 204),
        ]);

        $db = $this->database();
        $site = Site::factory()->create(['organization_id' => $db->organization_id]);
        $db->sites()->attach($site->id);

        (new TeardownCloudDatabaseJob($db->id))->handle();

        $this->assertDatabaseMissing('cloud_database_site', ['cloud_database_id' => $db->id]);
    }

    public function test_deletes_row_even_without_backend_id(): void
    {
        Http::fake();
        $db = $this->database(['backend_id' => null]);

        (new TeardownCloudDatabaseJob($db->id))->handle();

        $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
        Http::assertNothingSent();
    }

    public function test_missing_database_is_a_no_op(): void
    {
        (new TeardownCloudDatabaseJob('01nope0000000000000000nope'))->handle();
        $this->assertTrue(true);
    }
}
