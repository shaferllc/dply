<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\SshKeyHandlersTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\PushSshKeyHandler;
use App\Modules\Imports\Services\Handlers\RevokeSshKeyHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
test('push generates keypair pushes to ploi and persists', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys' => Http::response([
            'data' => ['id' => 9001, 'name' => 'dply-migrate-xyz'],
        ], 201),
    ]);

    [$migration, $step] = seedPushStep();

    $this->app->make(PushSshKeyHandler::class)->execute($step);

    $migration->refresh();
    expect($migration->ssh_key_source_id)->toBe(9001);
    expect($migration->ssh_key_public)->not->toBeNull();
    expect($migration->ssh_key_public)->toStartWith('ssh-ed25519 ');
    expect($migration->ssh_key_private_encrypted)->not->toBeNull();
    expect(Crypt::decryptString($migration->ssh_key_private_encrypted))->not->toBeEmpty();
    expect($migration->ssh_key_fingerprint)->not->toBeNull();
    expect($migration->ssh_key_pushed_at)->not->toBeNull();

    $step->refresh();
    expect($step->result_data['ssh_key_source_id'])->toBe(9001);
});
test('push is idempotent when already pushed', function () {
    Http::fake();
    [$migration, $step] = seedPushStep();
    $migration->ssh_key_source_id = 1234;
    $migration->save();

    $this->app->make(PushSshKeyHandler::class)->execute($step);

    Http::assertNothingSent();
});
test('revoke calls delete and stamps revoked at', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
    ]);

    [$migration, $step] = seedRevokeStep(9001);

    $this->app->make(RevokeSshKeyHandler::class)->execute($step);

    $migration->refresh();
    expect($migration->ssh_key_revoked_at)->not->toBeNull();
    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && $req->url() === 'https://ploi.io/api/servers/42/keys/9001');
});
test('revoke is a noop when no key was ever pushed', function () {
    Http::fake();
    [$migration, $step] = seedRevokeStep(null);

    $this->app->make(RevokeSshKeyHandler::class)->execute($step);

    $migration->refresh();
    expect($migration->ssh_key_revoked_at)->toBeNull();
    Http::assertNothingSent();
});
/**
 * @return array{0: ImportServerMigration, 1: ImportMigrationStep}
 */
function seedPushStep(): array
{
    $migration = seedMigration();
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    return [$migration, $step];
}
/**
 * @return array{0: ImportServerMigration, 1: ImportMigrationStep}
 */
function seedRevokeStep(?int $sourceKeyId): array
{
    $migration = seedMigration();
    $migration->ssh_key_source_id = $sourceKeyId;
    $migration->save();

    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 99,
        'step_key' => ImportMigrationStep::KEY_REVOKE_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    return [$migration, $step];
}
function seedMigration(): ImportServerMigration
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);

    return ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'status' => ImportServerMigration::STATUS_PENDING,
    ]);
}
