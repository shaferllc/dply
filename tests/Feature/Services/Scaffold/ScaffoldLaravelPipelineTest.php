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
use App\Services\Scaffold\PlaceholderDnsManager;
use App\Services\Scaffold\PrerequisiteResult;
use App\Services\Scaffold\ScaffoldLaravelPipeline;
use App\Services\Scaffold\ScaffoldPrerequisites;
use App\Services\Scaffold\ScaffoldStep;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScaffoldLaravelPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mock PlaceholderDnsManager that records the call and returns a
     * stable nip.io-shaped hostname so downstream URL writes are
     * deterministic + the test never makes a real HTTP request.
     */
    private function placeholderDnsAlwaysAssigns(Site $site, string $hostname = 'my-laravel-app.198-51-100-7.nip.io'): PlaceholderDnsManager
    {
        $mock = Mockery::mock(PlaceholderDnsManager::class);
        $mock->shouldReceive('assign')
            ->andReturn([
                'hostname' => $hostname,
                'zone' => null,
                'record_id' => null,
                'source' => 'nip.io',
            ]);
        $mock->shouldReceive('release')->andReturnNull();

        return $mock;
    }

    private function makeScaffoldingSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['database' => 'mysql84'],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'My Laravel App',
            'slug' => 'my-laravel-app',
            'status' => Site::STATUS_SCAFFOLDING,
            'meta' => [
                'scaffold' => [
                    'framework' => 'laravel',
                    'admin_email' => 'admin@example.com',
                ],
            ],
        ]);
    }

    public function test_happy_path_walks_all_steps_and_settles_pending(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureComposer')
            ->once()
            ->andReturn(PrerequisiteResult::alreadyPresent('composer'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')
            ->once()
            ->andReturn('CREATE DATABASE ok');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

        $audit = app(\App\Services\RemoteCli\SiteAuditWriter::class);

        $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, $this->placeholderDnsAlwaysAssigns($site)))->run($site);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['failed_step']);

        $site->refresh();
        $this->assertSame(Site::STATUS_PENDING, $site->status);

        $steps = collect($site->meta['scaffold']['steps']);
        // 9 = original 8 + the new placeholder_dns step (PR-follow-up wiring)
        $this->assertCount(9, $steps);
        $this->assertTrue($steps->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED),
            'All steps should have settled to completed; got: '.json_encode($steps->pluck('state')));

        // Admin password recorded encrypted.
        $this->assertNotEmpty($site->meta['scaffold']['admin_password']);
        $this->assertSame(20, strlen(decrypt($site->meta['scaffold']['admin_password'])));

        // Database row created.
        $db = ServerDatabase::query()->sole();
        $this->assertSame('dply_my_laravel_app', $db->name);
        $this->assertSame('mysql84', $db->engine);

        // Audit event for the success.
        $event = SiteAuditEvent::query()->where('action', 'scaffold_completed')->sole();
        $this->assertSame(SiteAuditEvent::RESULT_SUCCESS, $event->result_status);
    }

    public function test_failed_prereq_marks_site_failed_and_audits(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureComposer')
            ->once()
            ->andReturn(PrerequisiteResult::failed('composer', 'no internet'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldNotReceive('createOnServer');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

        $audit = app(\App\Services\RemoteCli\SiteAuditWriter::class);

        $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, $this->placeholderDnsAlwaysAssigns($site)))->run($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('prereqs', $result['failed_step']);
        $this->assertStringContainsString('no internet', $result['error']);

        $site->refresh();
        $this->assertSame(Site::STATUS_SCAFFOLD_FAILED, $site->status);

        $failedStep = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'prereqs');
        $this->assertSame(ScaffoldStep::STATE_FAILED, $failedStep['state']);

        // db_create stays pending — pipeline aborted before that step.
        $dbStep = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'db_create');
        $this->assertSame(ScaffoldStep::STATE_PENDING, $dbStep['state']);

        $event = SiteAuditEvent::query()->where('action', 'scaffold_failed')->sole();
        $this->assertSame('prereqs', $event->payload['step']);
    }

    public function test_executor_failure_aborts_at_that_step(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));

        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // composer create-project blows up — simulate exit 2.
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-laravel:composer-create')
            ->andReturn(new ProcessOutput('disk full', 2, false));

        $audit = app(\App\Services\RemoteCli\SiteAuditWriter::class);

        $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, $this->placeholderDnsAlwaysAssigns($site)))->run($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('composer_create', $result['failed_step']);

        $site->refresh();
        $this->assertSame(Site::STATUS_SCAFFOLD_FAILED, $site->status);
    }

    public function test_placeholder_dns_step_creates_primary_site_domain(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));
        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');
        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

        $dns = $this->placeholderDnsAlwaysAssigns($site, hostname: 'my-laravel-app.203-0-113-7.nip.io');

        (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, app(\App\Services\RemoteCli\SiteAuditWriter::class), $dns))
            ->run($site);

        $domain = $site->fresh()->primaryDomain();
        $this->assertNotNull($domain, 'Pipeline must persist a primary SiteDomain row from the placeholder hostname');
        $this->assertSame('my-laravel-app.203-0-113-7.nip.io', $domain->hostname);
        $this->assertTrue($domain->is_primary);
    }

    public function test_write_env_uses_placeholder_hostname_in_app_url(): void
    {
        $site = $this->makeScaffoldingSite();

        $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
        $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));
        $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
        $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

        $hostnameSeen = null;
        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // Capture the bash that write_env runs so we can grep for APP_URL.
        $executor->shouldReceive('runInlineBash')
            ->withArgs(function ($s, string $name, string $bash) use (&$hostnameSeen) {
                if ($name === 'scaffold-laravel:write-env') {
                    $hostnameSeen = $bash;
                }

                return true;
            })
            ->andReturn(new ProcessOutput('ok', 0, false));

        $dns = $this->placeholderDnsAlwaysAssigns($site, hostname: 'my-laravel-app.198-51-100-9.nip.io');

        (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, app(\App\Services\RemoteCli\SiteAuditWriter::class), $dns))
            ->run($site);

        $this->assertNotNull($hostnameSeen, 'write_env step should have run');
        $this->assertStringContainsString('APP_URL=http://my-laravel-app.198-51-100-9.nip.io', $hostnameSeen);
    }
}
