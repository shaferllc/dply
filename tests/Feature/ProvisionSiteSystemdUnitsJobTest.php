<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RemoteShell;
use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProvisionSiteSystemdUnitsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_provisions_units_for_node_site(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
        ]);

        $shell = new ProvisionRecordingShell;
        $provisioner = new class($shell) extends SiteSystemdProvisioner {
            public function __construct(private RemoteShell $shell)
            {
                parent::__construct(new SiteSystemdUnitBuilder);
            }

            public function provision(Site $site, ?\Closure $shellFactory = null): array
            {
                return parent::provision($site, fn () => $this->shell);
            }
        };
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);

        // Web unit was uploaded; daemon-reload was called.
        $this->assertNotEmpty($shell->putFiles);
        $this->assertTrue(collect($shell->execCalls)->contains(
            fn ($c) => $c === 'sudo systemctl daemon-reload'
        ));
    }

    public function test_job_skips_php_runtime(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'php',
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('provision');

        (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);
    }

    public function test_job_skips_when_site_has_no_start_command(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => null,
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('provision');

        (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);
    }

    public function test_job_silently_returns_when_site_does_not_exist(): void
    {
        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('provision');

        (new ProvisionSiteSystemdUnitsJob('01HMISSINGIDDOESNOTEXIST00'))
            ->handle($provisioner);

        // Returning normally is the assertion.
        $this->assertTrue(true);
    }

    public function test_job_provisions_units_for_each_non_web_process(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'python',
            'start_command' => 'gunicorn app:app',
            'internal_port' => 30002,
        ]);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'celery',
            'command' => 'celery -A app worker',
        ]);

        $shell = new ProvisionRecordingShell;
        $provisioner = new class($shell) extends SiteSystemdProvisioner {
            public function __construct(private RemoteShell $shell)
            {
                parent::__construct(new SiteSystemdUnitBuilder);
            }

            public function provision(Site $site, ?\Closure $shellFactory = null): array
            {
                return parent::provision($site, fn () => $this->shell);
            }
        };

        (new ProvisionSiteSystemdUnitsJob($site->id))->handle($provisioner);

        // Two units uploaded (web + celery).
        $this->assertCount(2, $shell->putFiles);
        $contents = collect($shell->putFiles)->pluck('contents');
        $this->assertTrue($contents->contains(fn ($c) => str_contains($c, 'gunicorn app:app')));
        $this->assertTrue($contents->contains(fn ($c) => str_contains($c, 'celery -A app worker')));
    }
}

class ProvisionRecordingShell implements RemoteShell
{
    /** @var list<array{path: string, contents: string}> */
    public array $putFiles = [];

    /** @var list<string> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = ['path' => $remotePath, 'contents' => $contents];
    }
}
