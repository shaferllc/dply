<?php

declare(strict_types=1);

namespace Tests\Feature\TeardownCloudDatabaseJobTest;
use App\Jobs\TeardownCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function database(array $overrides = []): CloudDatabase
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
test('deletes cluster and removes row', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(null, 204),
    ]);

    $db = database();
    (new TeardownCloudDatabaseJob($db->id))->handle();

    $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && str_contains($req->url(), '/v2/databases/do-db-tear'));
});
test('is idempotent when cluster already gone', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(['id' => 'not_found'], 404),
    ]);

    $db = database();
    (new TeardownCloudDatabaseJob($db->id))->handle();

    $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
});
test('detaches pivot links before delete', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-db-tear' => Http::response(null, 204),
    ]);

    $db = database();
    $site = Site::factory()->create(['organization_id' => $db->organization_id]);
    $db->sites()->attach($site->id);

    (new TeardownCloudDatabaseJob($db->id))->handle();

    $this->assertDatabaseMissing('cloud_database_site', ['cloud_database_id' => $db->id]);
});
test('deletes row even without backend id', function () {
    Http::fake();
    $db = database(['backend_id' => null]);

    (new TeardownCloudDatabaseJob($db->id))->handle();

    $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
    Http::assertNothingSent();
});
test('missing database is a no op', function () {
    (new TeardownCloudDatabaseJob('01nope0000000000000000nope'))->handle();
    expect(true)->toBeTrue();
});
