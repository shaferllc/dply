<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\CloneRepoHandlerTest;
use \App\Services\SshConnectionFactory;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\Handlers\CloneRepoHandler;
use App\Services\Imports\WaitForTargetServerException;
use App\Services\Imports\WaitForTargetSiteException;
use App\Services\SshConnection;
use Tests\Support\Imports\RecordingShell;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: Site, 2: Server}
 */
function seedFixture(string $serverStatus = Server::STATUS_READY, string $siteStatus = Site::STATUS_NGINX_ACTIVE): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $target = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $serverStatus,
    ]);
    $site = Site::factory()->create([
        'server_id' => $target->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'slug' => 'acme-app',
        'status' => $siteStatus,
        'git_repository_url' => 'git@github.com:acme/app.git',
        'git_branch' => 'main',
        'repository_path' => null, // exercise the convention path
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'target_server_id' => $target->id,
        'status' => ImportServerMigration::STATUS_STAGING,
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
        'sequence' => 11,
        'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    return [$step, $site, $target];
}
function handlerWithShell(RecordingShell $shell): CloneRepoHandler
{
    $factory = new class($shell) extends SshConnectionFactory {
        function __construct(private RecordingShell $shell)
        {
        }

        function forServer(Server $server): SshConnection
        {
            return $this->shell;
        }
    };

    return new CloneRepoHandler($factory);
}
test('clones repo when directory absent', function () {
    $shell = new RecordingShell();

    // First call: probe rev-parse → returns empty/false (no work tree).
    $shell->responses[] = "false\n";

    // Second call: git clone … 2>&1 → produces some output.
    $shell->responses[] = "Cloning into 'acme-app'...\n";

    // Third call: rev-parse HEAD → returns a sha.
    $shell->responses[] = "abcdef1234567890abcdef1234567890abcdef12\n";

    [$step] = seedFixture();
    handlerWithShell($shell)->execute($step);

    $step->refresh();
    expect($step->result_data['head'])->toBe('abcdef1234567890abcdef1234567890abcdef12');
    expect($step->result_data['site_root'])->toBe('/home/acme-app/acme-app');
    expect($shell->commands)->toHaveCount(3);
    $this->assertStringContainsString('git clone', $shell->commands[1]);
    $this->assertStringContainsString('--branch \'main\'', $shell->commands[1]);
    $this->assertStringContainsString('git@github.com:acme/app.git', $shell->commands[1]);
});
test('is a noop when repo already cloned', function () {
    $shell = new RecordingShell();
    $shell->responses[] = "true\n";
    // probe says already a work tree
    [$step] = seedFixture();

    handlerWithShell($shell)->execute($step);

    $step->refresh();
    expect($step->result_data['already_cloned'])->toBeTrue();
    expect($shell->commands)->toHaveCount(1);
});
test('throws wait when target server not ready', function () {
    $shell = new RecordingShell();
    [$step] = seedFixture(serverStatus: Server::STATUS_PROVISIONING);

    $this->expectException(WaitForTargetServerException::class);
    handlerWithShell($shell)->execute($step);
    expect($shell->commands)->toHaveCount(0);
});
test('throws wait when target site not provisioned', function () {
    $shell = new RecordingShell();
    [$step] = seedFixture(siteStatus: Site::STATUS_PENDING);

    $this->expectException(WaitForTargetSiteException::class);
    handlerWithShell($shell)->execute($step);
    expect($shell->commands)->toHaveCount(0);
});
test('fails when post clone head probe returns garbage', function () {
    $shell = new RecordingShell();
    $shell->responses[] = "false\n";
    // not a work tree
    $shell->responses[] = "error: cannot clone — repo not found\n";
    $shell->responses[] = "fatal: not a git repository\n";

    [$step] = seedFixture();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/git clone failed/');
    handlerWithShell($shell)->execute($step);
});

