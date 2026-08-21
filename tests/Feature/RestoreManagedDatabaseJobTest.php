<?php

declare(strict_types=1);

namespace Tests\Feature\RestoreManagedDatabaseJobTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\RestoreManagedDatabaseJob;
use App\Modules\Database\Services\ManagedDatabaseBackups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: CloudDatabase, 1: Organization}
 */
function sourceDatabase(array $overrides = []): array
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

    $database = CloudDatabase::factory()->active()->create(array_merge([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'backend_id' => 'do-src-1',
        'name' => 'orders',
    ], $overrides));

    return [$database, $org];
}

test('restore creates a sibling row and queues the job', function () {
    Bus::fake();
    [$source] = sourceDatabase();

    $target = app(ManagedDatabaseBackups::class)->restore($source, 'orders-restore', '2026-08-20T04:00:00Z');

    expect($target->name)->toBe('orders-restore')
        ->and($target->status)->toBe(CloudDatabase::STATUS_PROVISIONING)
        ->and($target->organization_id)->toBe($source->organization_id)
        ->and($target->engine)->toBe($source->engine)
        ->and($target->size)->toBe($source->size)
        ->and($target->region)->toBe($source->region)
        ->and($target->provider_credential_id)->toBe($source->provider_credential_id)
        ->and($target->meta['restored_from']['cloud_database_id'])->toBe($source->id)
        ->and($target->meta['restored_from']['backup_created_at'])->toBe('2026-08-20T04:00:00Z');

    // The source is untouched — that is the whole point of restore-to-new.
    expect($source->fresh()?->status)->toBe(CloudDatabase::STATUS_ACTIVE);

    Bus::assertDispatched(
        RestoreManagedDatabaseJob::class,
        fn (RestoreManagedDatabaseJob $job): bool => $job->targetDatabaseId === $target->id
            && $job->sourceDatabaseId === $source->id,
    );
});

test('the job creates the cluster from the backup and re dispatches while restoring', function () {
    Bus::fake();
    [$source, $org] = sourceDatabase();
    $target = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $source->provider_credential_id,
        'name' => 'orders-restore',
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-src-1' => Http::response([
            'database' => ['id' => 'do-src-1', 'name' => 'dply-orders-abc123', 'status' => 'online', 'engine' => 'pg', 'connection' => []],
        ]),
        // Polled right after the create, while the restore is still replaying.
        // Http::fake lets an unmatched URL hit the network for real, so every
        // call the job makes needs a stub here.
        'https://api.digitalocean.com/v2/databases/do-new-1*' => Http::response([
            'database' => ['id' => 'do-new-1', 'name' => 'dply-orders-restore-xyz', 'status' => 'creating', 'engine' => 'pg', 'connection' => []],
        ]),
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => ['id' => 'do-new-1', 'name' => 'dply-orders-restore-xyz', 'status' => 'creating', 'engine' => 'pg', 'connection' => []],
        ], 201),
    ]);

    (new RestoreManagedDatabaseJob($target->id, $source->id, '2026-08-20T04:00:00Z'))->handle(app(DatabaseRouter::class));

    expect($target->fresh()?->backend_id)->toBe('do-new-1');

    // The create must carry the source's provider-side name, not its id.
    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        return ($request['backup_restore']['database_name'] ?? null) === 'dply-orders-abc123'
            && ($request['backup_restore']['backup_created_at'] ?? null) === '2026-08-20T04:00:00Z';
    });

    Bus::assertDispatched(
        RestoreManagedDatabaseJob::class,
        fn (RestoreManagedDatabaseJob $job): bool => $job->attempt === 2,
    );
});

test('the job stores the connection once the restored cluster is online', function () {
    [$source, $org] = sourceDatabase();
    $target = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $source->provider_credential_id,
        'backend_id' => 'do-new-2',
        'name' => 'orders-restore',
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/databases/do-new-2*' => Http::response([
            'database' => [
                'id' => 'do-new-2',
                'name' => 'dply-orders-restore-xyz',
                'status' => 'online',
                'engine' => 'pg',
                'connection' => [
                    'host' => 'restored.ondigitalocean.com',
                    'port' => 25060,
                    'user' => 'doadmin',
                    'password' => 'restored-pass',
                    'database' => 'defaultdb',
                    'ssl' => true,
                ],
            ],
        ]),
    ]);

    (new RestoreManagedDatabaseJob($target->id, $source->id, '2026-08-20T04:00:00Z'))->handle(app(DatabaseRouter::class));

    $fresh = $target->fresh();
    expect($fresh?->status)->toBe(CloudDatabase::STATUS_ACTIVE)
        ->and($fresh?->getAttribute('connection')['host'])->toBe('restored.ondigitalocean.com')
        ->and($fresh?->getAttribute('connection')['username'])->toBe('doadmin');

    // A restore is never wired into anything automatically.
    expect($fresh?->sites()->count())->toBe(0);
});

test('a missing source marks the target failed', function () {
    [$source, $org] = sourceDatabase();
    $target = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $source->provider_credential_id,
    ]);
    $sourceId = $source->id;
    $source->delete();

    (new RestoreManagedDatabaseJob($target->id, $sourceId, '2026-08-20T04:00:00Z'))->handle(app(DatabaseRouter::class));

    expect($target->fresh()?->status)->toBe(CloudDatabase::STATUS_FAILED);
});
