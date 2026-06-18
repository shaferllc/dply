<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\DumpRestoreHandlersTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\DumpDatabaseHandler;
use App\Modules\Imports\Services\Handlers\RestoreDatabaseHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Imports\FakeSourceSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;

uses(RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server}
 */
function seedFixture(string $stepKey, ?string $dumpPath = null): array
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
test('dump runs mysqldump via ploi ssh and records metadata', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
            'data' => [['id' => 7, 'name' => 'acme_app_prod', 'user' => 'acme']],
        ], 200),
    ]);

    $shell = new RecordingShell;

    // mysqldump command — output captured, no exit code from phpseclib's exec
    $shell->responses[] = '';

    // stat -c %s … — returns size of the dump file
    $shell->responses[] = "8123456\n";

    [$step] = seedFixture(ImportMigrationStep::KEY_DUMP_DB);

    $handler = new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell));
    $handler->execute($step);

    $step->refresh();
    expect($step->result_data['database'])->toBe('acme_app_prod');
    expect($step->result_data['bytes'])->toBe(8123456);
    expect($shell->commands)->toHaveCount(2);
    $this->assertStringContainsString('mysqldump --defaults-extra-file=/home/ploi/.my.cnf --single-transaction --routines --triggers', $shell->commands[0]);
    $this->assertStringContainsString("'acme_app_prod'", $shell->commands[0]);
    $this->assertStringContainsString('stat -c %s', $shell->commands[1]);
});
test('dump skips when site has no database', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response(['data' => []], 200),
    ]);
    $shell = new RecordingShell;
    [$step] = seedFixture(ImportMigrationStep::KEY_DUMP_DB);

    $handler = new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell));
    $handler->execute($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
    expect($step->result_data['reason'])->toBe('no_database_on_source_site');
    expect($shell->commands)->toHaveCount(0);
});
test('dump flags warning when site has multiple databases', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
            'data' => [
                ['id' => 7, 'name' => 'primary', 'user' => 'acme'],
                ['id' => 8, 'name' => 'analytics', 'user' => 'acme'],
            ],
        ], 200),
    ]);
    $shell = new RecordingShell;
    $shell->responses[] = '';
    $shell->responses[] = "1024\n";

    [$step] = seedFixture(ImportMigrationStep::KEY_DUMP_DB);
    (new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell)))->execute($step);

    $step->refresh();
    expect($step->result_data['warnings'])->not->toBeEmpty();
    $this->assertStringContainsString('analytics', $step->result_data['warnings'][0]);
});
test('dump throws when dump file is empty', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
            'data' => [['id' => 7, 'name' => 'primary', 'user' => 'acme']],
        ], 200),
    ]);
    $shell = new RecordingShell;
    $shell->responses[] = 'mysqldump: Got error 1045 — access denied';
    $shell->responses[] = "0\n";

    [$step] = seedFixture(ImportMigrationStep::KEY_DUMP_DB);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/mysqldump produced empty file/');
    (new DumpDatabaseHandler(new FakeSourceSshConnectionFactory($shell)))->execute($step);
});
test('restore pulls dump from ploi and restores on dply', function () {
    $dumpBytes = "-- MySQL dump\nINSERT INTO users …\n";
    $shellPloi = new RecordingShell;
    $shellPloi->responses[] = base64_encode($dumpBytes);

    // Cleanup rm at the end:
    $shellPloi->responses[] = '';

    $shellDply = new RecordingShell;
    $shellDply->responses[] = 'mysql restore OK';

    [$step, , , $site] = seedFixture(ImportMigrationStep::KEY_RESTORE_DB, dumpPath: '/tmp/dply-migrate-xyz-100.sql');

    $handler = new RestoreDatabaseHandler(
        new FakeSshConnectionFactory($shellDply),
        new FakeSourceSshConnectionFactory($shellPloi),
    );
    $handler->execute($step);

    // The dump should have been written to dply's tmp via putFile
    expect($shellDply->written)->toHaveCount(1);
    $writtenPath = array_key_first($shellDply->written);
    expect($writtenPath)->toStartWith('/tmp/dply-restore-');
    expect($shellDply->written[$writtenPath])->toBe($dumpBytes);

    // mysql restore command should reference the dply database (slug→underscore)
    expect($shellDply->commands)->toHaveCount(1);
    $this->assertStringContainsString('mysql --defaults-extra-file=/root/.my.cnf', $shellDply->commands[0]);
    $this->assertStringContainsString(escapeshellarg('acme_app'), $shellDply->commands[0]);

    $step->refresh();
    expect($step->result_data['source_database'])->toBe('acme_app_prod');
    expect($step->result_data['target_database'])->toBe('acme_app');
    expect($step->result_data['bytes'])->toBe(strlen($dumpBytes));
});
test('restore skips when dump was skipped', function () {
    $shellPloi = new RecordingShell;
    $shellDply = new RecordingShell;

    [$step, $child] = seedFixture(ImportMigrationStep::KEY_RESTORE_DB);

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
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
    expect($step->result_data['reason'])->toBe('dump_was_skipped');
    expect($shellDply->commands)->toHaveCount(0);
    expect($shellPloi->commands)->toHaveCount(0);
});
test('restore throws when dump step missing', function () {
    [$step] = seedFixture(ImportMigrationStep::KEY_RESTORE_DB);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/dump_database to have run/');

    (new RestoreDatabaseHandler(
        new FakeSshConnectionFactory(new RecordingShell),
        new FakeSourceSshConnectionFactory(new RecordingShell),
    ))->execute($step);
});
