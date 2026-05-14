<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\Handlers\DumpDatabaseHandler;
use App\Services\Imports\Handlers\RestoreDatabaseHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Imports\FakeSourceSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;
use Tests\TestCase;

class DumpRestoreHandlersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server}
     */
    protected function seedFixture(string $stepKey, ?string $dumpPath = null): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_token'],
        ]);
        PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'src',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => [],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        $target = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => Server::STATUS_READY,
        ]);
        $site = Site::factory()->create([
            'server_id' => $target->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'slug' => 'acme-app',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'target_server_id' => $target->id,
            'status' => ImportServerMigration::STATUS_STAGING,
            'ssh_key_private_encrypted' => 'unused-here',
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_STAGING,
            'source_snapshot' => [],
            'target_site_id' => $site->id,
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 12,
            'step_key' => $stepKey,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        if ($dumpPath !== null && $stepKey === ImportMigrationStep::KEY_RESTORE_DB) {
            // Prior dump_database row that RestoreDatabaseHandler queries.
            ImportMigrationStep::create([
                'import_server_migration_id' => $migration->id,
                'import_site_migration_id' => $child->id,
                'sequence' => 11,
                'step_key' => ImportMigrationStep::KEY_DUMP_DB,
                'status' => ImportMigrationStep::STATUS_SUCCEEDED,
                'result_data' => ['dump_path' => $dumpPath, 'database' => 'acme_app_prod'],
            ]);
        }

        return [$step, $child, $migration, $site, $target];
    }

    public function test_dump_runs_mysqldump_via_ploi_ssh_and_records_metadata(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
                'data' => [['id' => 7, 'name' => 'acme_app_prod', 'user' => 'acme']],
            ], 200),
        ]);

        $shell = new RecordingShell();
        // mysqldump command — output captured, no exit code from phpseclib's exec
        $shell->responses[] = '';
        // stat -c %s … — returns size of the dump file
        $shell->responses[] = "8123456\n";

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_DUMP_DB);

        $handler = new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell));
        $handler->execute($step);

        $step->refresh();
        $this->assertSame('acme_app_prod', $step->result_data['database']);
        $this->assertSame(8123456, $step->result_data['bytes']);
        $this->assertCount(2, $shell->commands);
        $this->assertStringContainsString('mysqldump --defaults-extra-file=/home/ploi/.my.cnf --single-transaction --routines --triggers', $shell->commands[0]);
        $this->assertStringContainsString("'acme_app_prod'", $shell->commands[0]);
        $this->assertStringContainsString('stat -c %s', $shell->commands[1]);
    }

    public function test_dump_skips_when_site_has_no_database(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response(['data' => []], 200),
        ]);
        $shell = new RecordingShell();
        [$step] = $this->seedFixture(ImportMigrationStep::KEY_DUMP_DB);

        $handler = new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell));
        $handler->execute($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SKIPPED, $step->status);
        $this->assertSame('no_database_on_source_site', $step->result_data['reason']);
        $this->assertCount(0, $shell->commands);
    }

    public function test_dump_flags_warning_when_site_has_multiple_databases(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
                'data' => [
                    ['id' => 7, 'name' => 'primary', 'user' => 'acme'],
                    ['id' => 8, 'name' => 'analytics', 'user' => 'acme'],
                ],
            ], 200),
        ]);
        $shell = new RecordingShell();
        $shell->responses[] = '';
        $shell->responses[] = "1024\n";

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_DUMP_DB);
        (new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell)))->execute($step);

        $step->refresh();
        $this->assertNotEmpty($step->result_data['warnings']);
        $this->assertStringContainsString('analytics', $step->result_data['warnings'][0]);
    }

    public function test_dump_throws_when_dump_file_is_empty(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
                'data' => [['id' => 7, 'name' => 'primary', 'user' => 'acme']],
            ], 200),
        ]);
        $shell = new RecordingShell();
        $shell->responses[] = 'mysqldump: Got error 1045 — access denied';
        $shell->responses[] = "0\n";

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_DUMP_DB);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysqldump produced empty file/');
        (new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell)))->execute($step);
    }

    public function test_restore_pulls_dump_from_ploi_and_restores_on_dply(): void
    {
        $dumpBytes = "-- MySQL dump\nINSERT INTO users …\n";
        $shellPloi = new RecordingShell();
        $shellPloi->responses[] = base64_encode($dumpBytes);
        // Cleanup rm at the end:
        $shellPloi->responses[] = '';

        $shellDply = new RecordingShell();
        $shellDply->responses[] = 'mysql restore OK';

        [$step, , , $site] = $this->seedFixture(
            ImportMigrationStep::KEY_RESTORE_DB,
            dumpPath: '/tmp/dply-migrate-xyz-100.sql'
        );

        $handler = new RestoreDatabaseHandler(
            new FakeSshConnectionFactory($shellDply),
            new FakeSourceSshConnectionFactory($shellPloi),
        );
        $handler->execute($step);

        // The dump should have been written to dply's tmp via putFile
        $this->assertCount(1, $shellDply->written);
        $writtenPath = array_key_first($shellDply->written);
        $this->assertStringStartsWith('/tmp/dply-restore-', $writtenPath);
        $this->assertSame($dumpBytes, $shellDply->written[$writtenPath]);

        // mysql restore command should reference the dply database (slug→underscore)
        $this->assertCount(1, $shellDply->commands);
        $this->assertStringContainsString('mysql --defaults-extra-file=/root/.my.cnf', $shellDply->commands[0]);
        $this->assertStringContainsString(escapeshellarg('acme_app'), $shellDply->commands[0]);

        $step->refresh();
        $this->assertSame('acme_app_prod', $step->result_data['source_database']);
        $this->assertSame('acme_app', $step->result_data['target_database']);
        $this->assertSame(strlen($dumpBytes), $step->result_data['bytes']);
    }

    public function test_restore_skips_when_dump_was_skipped(): void
    {
        $shellPloi = new RecordingShell();
        $shellDply = new RecordingShell();

        [$step, $child] = $this->seedFixture(ImportMigrationStep::KEY_RESTORE_DB);
        // Synthesize a SKIPPED dump step.
        ImportMigrationStep::create([
            'import_server_migration_id' => $step->import_server_migration_id,
            'import_site_migration_id' => $child->id,
            'sequence' => 11,
            'step_key' => ImportMigrationStep::KEY_DUMP_DB,
            'status' => ImportMigrationStep::STATUS_SKIPPED,
            'result_data' => ['reason' => 'no_database_on_source_site'],
        ]);

        (new RestoreDatabaseHandler(
            new FakeSshConnectionFactory($shellDply),
            new FakeSourceSshConnectionFactory($shellPloi),
        ))->execute($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SKIPPED, $step->status);
        $this->assertSame('dump_was_skipped', $step->result_data['reason']);
        $this->assertCount(0, $shellDply->commands);
        $this->assertCount(0, $shellPloi->commands);
    }

    public function test_restore_throws_when_dump_step_missing(): void
    {
        [$step] = $this->seedFixture(ImportMigrationStep::KEY_RESTORE_DB);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dump_database to have run/');

        (new RestoreDatabaseHandler(
            new FakeSshConnectionFactory(new RecordingShell()),
            new FakeSourceSshConnectionFactory(new RecordingShell()),
        ))->execute($step);
    }
}
