<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionCloudDatabaseJobTest;

use App\Modules\Cloud\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

    return CloudDatabase::factory()->create(array_merge([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
    ], $overrides));
}
test('creates cluster and re dispatches while still provisioning', function () {
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

    $db = database();
    (new ProvisionCloudDatabaseJob($db->id))->handle();

    $fresh = $db->fresh();
    expect($fresh->backend_id)->toBe('do-db-1');
    expect($fresh->status)->toBe(CloudDatabase::STATUS_PROVISIONING);

    Bus::assertDispatched(ProvisionCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id && $j->attempt === 2);
});
test('polls existing cluster until online then stores connection', function () {
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

    $db = database(['backend_id' => 'do-db-9', 'status' => CloudDatabase::STATUS_PROVISIONING]);
    (new ProvisionCloudDatabaseJob($db->id, 2))->handle();

    $fresh = $db->fresh();
    expect($fresh->status)->toBe(CloudDatabase::STATUS_ACTIVE);
    expect($fresh->connection['host'])->toBe('db-9.ondigitalocean.com');
    expect($fresh->connection['username'])->toBe('doadmin');
    expect($fresh->connection['password'])->toBe('sup3r secret');
    expect($fresh->connection['database'])->toBe('defaultdb');
});
test('marks failed on backend error', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases' => Http::response(['message' => 'bad'], 422),
    ]);

    $db = database();
    (new ProvisionCloudDatabaseJob($db->id))->handle();

    $fresh = $db->fresh();
    expect($fresh->status)->toBe(CloudDatabase::STATUS_FAILED);
    expect($fresh->meta['error'])->not->toBeEmpty();
});
test('marks failed without a credential', function () {
    $org = Organization::factory()->create();
    $db = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => null,
    ]);

    (new ProvisionCloudDatabaseJob($db->id))->handle();

    expect($db->fresh()->status)->toBe(CloudDatabase::STATUS_FAILED);
});
test('missing database is a no op', function () {
    (new ProvisionCloudDatabaseJob('01nope0000000000000000nope'))->handle();
    expect(true)->toBeTrue();
});
test('already active database is skipped', function () {
    Http::fake();
    $db = database(['status' => CloudDatabase::STATUS_ACTIVE, 'backend_id' => 'do-db-x']);

    (new ProvisionCloudDatabaseJob($db->id))->handle();

    Http::assertNothingSent();
});
test('gives up after max attempts', function () {
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

    $db = database(['backend_id' => 'do-db-slow', 'status' => CloudDatabase::STATUS_PROVISIONING]);
    (new ProvisionCloudDatabaseJob($db->id, 40))->handle();

    expect($db->fresh()->status)->toBe(CloudDatabase::STATUS_FAILED);
    Bus::assertNotDispatched(ProvisionCloudDatabaseJob::class);
});
