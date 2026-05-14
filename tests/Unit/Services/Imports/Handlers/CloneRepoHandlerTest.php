<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Contracts\RemoteShell;
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
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Imports\RecordingShell;
use Tests\TestCase;

class CloneRepoHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: Site, 2: Server}
     */
    protected function seedFixture(
        string $serverStatus = Server::STATUS_READY,
        string $siteStatus = Site::STATUS_NGINX_ACTIVE,
    ): array {
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

    protected function handlerWithShell(RecordingShell $shell): CloneRepoHandler
    {
        $factory = new class($shell) extends SshConnectionFactory {
            public function __construct(private RecordingShell $shell) {}

            public function forServer(Server $server): RemoteShell
            {
                return $this->shell;
            }
        };

        return new CloneRepoHandler($factory);
    }

    public function test_clones_repo_when_directory_absent(): void
    {
        $shell = new RecordingShell();
        // First call: probe rev-parse → returns empty/false (no work tree).
        $shell->responses[] = "false\n";
        // Second call: git clone … 2>&1 → produces some output.
        $shell->responses[] = "Cloning into 'acme-app'...\n";
        // Third call: rev-parse HEAD → returns a sha.
        $shell->responses[] = "abcdef1234567890abcdef1234567890abcdef12\n";

        [$step] = $this->seedFixture();
        $this->handlerWithShell($shell)->execute($step);

        $step->refresh();
        $this->assertSame('abcdef1234567890abcdef1234567890abcdef12', $step->result_data['head']);
        $this->assertSame('/home/acme-app/acme-app', $step->result_data['site_root']);
        $this->assertCount(3, $shell->commands);
        $this->assertStringContainsString('git clone', $shell->commands[1]);
        $this->assertStringContainsString('--branch \'main\'', $shell->commands[1]);
        $this->assertStringContainsString('git@github.com:acme/app.git', $shell->commands[1]);
    }

    public function test_is_a_noop_when_repo_already_cloned(): void
    {
        $shell = new RecordingShell();
        $shell->responses[] = "true\n"; // probe says already a work tree
        [$step] = $this->seedFixture();

        $this->handlerWithShell($shell)->execute($step);

        $step->refresh();
        $this->assertTrue($step->result_data['already_cloned']);
        $this->assertCount(1, $shell->commands);
    }

    public function test_throws_wait_when_target_server_not_ready(): void
    {
        $shell = new RecordingShell();
        [$step] = $this->seedFixture(serverStatus: Server::STATUS_PROVISIONING);

        $this->expectException(WaitForTargetServerException::class);
        $this->handlerWithShell($shell)->execute($step);
        $this->assertCount(0, $shell->commands);
    }

    public function test_throws_wait_when_target_site_not_provisioned(): void
    {
        $shell = new RecordingShell();
        [$step] = $this->seedFixture(siteStatus: Site::STATUS_PENDING);

        $this->expectException(WaitForTargetSiteException::class);
        $this->handlerWithShell($shell)->execute($step);
        $this->assertCount(0, $shell->commands);
    }

    public function test_fails_when_post_clone_head_probe_returns_garbage(): void
    {
        $shell = new RecordingShell();
        $shell->responses[] = "false\n";          // not a work tree
        $shell->responses[] = "error: cannot clone — repo not found\n";
        $shell->responses[] = "fatal: not a git repository\n";

        [$step] = $this->seedFixture();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/git clone failed/');
        $this->handlerWithShell($shell)->execute($step);
    }
}

