<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Scaffold\PrerequisiteResult;
use App\Services\Scaffold\ScaffoldPrerequisites;
use App\Services\Scaffold\ScaffoldStep;
use App\Services\Scaffold\ScaffoldWordPressPipeline;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScaffoldWordPressPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeScaffoldingSite(string $serverEngine = 'mariadb114'): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['database' => $serverEngine],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'My WP Blog',
            'slug' => 'my-wp-blog',
            'status' => Site::STATUS_SCAFFOLDING,
            'meta' => [
                'scaffold' => [
                    'framework' => 'wordpress',
                    'admin_email' => 'admin@example.com',
                ],
            ],
        ]);
    }

    public function test_happy_path_walks_all_six_steps(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

        $audit = app(\App\Services\RemoteCli\SiteAuditWriter::class);

        $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, $audit))->run($site);

        $this->assertTrue($result['ok']);

        $site->refresh();
        $this->assertSame(Site::STATUS_PENDING, $site->status);

        $steps = collect($site->meta['scaffold']['steps']);
        $this->assertCount(6, $steps);
        $this->assertTrue($steps->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED));

        // Q18 hardening opinions recorded for the Hardening tab to read.
        $this->assertNotEmpty($site->meta['scaffold']['hardening']);
        $this->assertCount(6, $site->meta['scaffold']['hardening']);

        $db = ServerDatabase::query()->sole();
        $this->assertSame('mariadb114', $db->engine);

        // Each opinion writes its own audit row.
        $opinionAudits = SiteAuditEvent::query()->where('action', 'scaffold_default_applied')->get();
        $this->assertCount(6, $opinionAudits);

        $completed = SiteAuditEvent::query()->where('action', 'scaffold_completed')->sole();
        $this->assertSame('wordpress', $completed->payload['framework']);
    }

    public function test_falls_back_to_mysql_when_server_engine_is_postgres(): void
    {
        // Defensive: even though the wizard should disable the WordPress
        // tile on Postgres-only hosts, the pipeline guards the engine
        // pick at runtime.
        $site = $this->makeScaffoldingSite(serverEngine: 'postgres17');

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

        (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(\App\Services\RemoteCli\SiteAuditWriter::class)))->run($site);

        $db = ServerDatabase::query()->sole();
        $this->assertSame('mysql84', $db->engine, 'Pipeline must fall back to mysql84 when server engine is incompatible');
    }

    public function test_failed_wp_install_marks_failed_and_audits(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // First three runs succeed (download, config, install setup),
        // wp_install (4th call) fails.
        $executor->shouldReceive('runInlineBash')
            ->withArgs(fn ($s, string $name) => $name !== 'scaffold-wp:core-install')
            ->andReturn(new ProcessOutput('ok', 0, false));
        $executor->shouldReceive('runInlineBash')
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-wp:core-install')
            ->andReturn(new ProcessOutput('Error: Could not connect to db', 1, false));

        $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(\App\Services\RemoteCli\SiteAuditWriter::class)))->run($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('wp_install', $result['failed_step']);

        $site->refresh();
        $this->assertSame(Site::STATUS_SCAFFOLD_FAILED, $site->status);

        // apply_hardening must NOT have run.
        $hardening = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'apply_hardening');
        $this->assertSame(ScaffoldStep::STATE_PENDING, $hardening['state']);
    }
}
