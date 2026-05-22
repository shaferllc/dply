<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\Handlers\PushSshKeyHandler;
use App\Services\Imports\Handlers\RevokeSshKeyHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SshKeyHandlersTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_generates_keypair_pushes_to_ploi_and_persists(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/keys' => Http::response([
                'data' => ['id' => 9001, 'name' => 'dply-migrate-xyz'],
            ], 201),
        ]);

        [$migration, $step] = $this->seedPushStep();

        $this->app->make(PushSshKeyHandler::class)->execute($step);

        $migration->refresh();
        $this->assertSame(9001, $migration->ssh_key_source_id);
        $this->assertNotNull($migration->ssh_key_public);
        $this->assertStringStartsWith('ssh-ed25519 ', $migration->ssh_key_public);
        $this->assertNotNull($migration->ssh_key_private_encrypted);
        $this->assertNotEmpty(Crypt::decryptString($migration->ssh_key_private_encrypted));
        $this->assertNotNull($migration->ssh_key_fingerprint);
        $this->assertNotNull($migration->ssh_key_pushed_at);

        $step->refresh();
        $this->assertSame(9001, $step->result_data['ssh_key_source_id']);
    }

    public function test_push_is_idempotent_when_already_pushed(): void
    {
        Http::fake();
        [$migration, $step] = $this->seedPushStep();
        $migration->ssh_key_source_id = 1234;
        $migration->save();

        $this->app->make(PushSshKeyHandler::class)->execute($step);

        Http::assertNothingSent();
    }

    public function test_revoke_calls_delete_and_stamps_revoked_at(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
        ]);

        [$migration, $step] = $this->seedRevokeStep(9001);

        $this->app->make(RevokeSshKeyHandler::class)->execute($step);

        $migration->refresh();
        $this->assertNotNull($migration->ssh_key_revoked_at);
        Http::assertSent(fn ($req) => $req->method() === 'DELETE'
            && $req->url() === 'https://ploi.io/api/servers/42/keys/9001');
    }

    public function test_revoke_is_a_noop_when_no_key_was_ever_pushed(): void
    {
        Http::fake();
        [$migration, $step] = $this->seedRevokeStep(null);

        $this->app->make(RevokeSshKeyHandler::class)->execute($step);

        $migration->refresh();
        $this->assertNull($migration->ssh_key_revoked_at);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: ImportServerMigration, 1: ImportMigrationStep}
     */
    protected function seedPushStep(): array
    {
        $migration = $this->seedMigration();
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
    protected function seedRevokeStep(?int $sourceKeyId): array
    {
        $migration = $this->seedMigration();
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

    protected function seedMigration(): ImportServerMigration
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
}
