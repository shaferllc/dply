<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSystemdProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_uploads_web_unit_and_activates_via_systemctl(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30007,
            'repository_path' => '/var/www/jobs',
            'deploy_strategy' => 'simple',
        ]);
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => 'npm start']);

        $shell = new RecordingShell;

        $written = (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
            ->provision($site, fn () => $shell);

        $this->assertContains("dply-site-{$site->id}.service", $written);
        $putFiles = $shell->putFiles;
        $this->assertCount(1, $putFiles);
        $this->assertStringContainsString('Environment=PORT=30007', $putFiles[0]['contents']);
        $this->assertStringContainsString('ExecStart=npm start', $putFiles[0]['contents']);

        // Verify the install + daemon-reload + enable sequence.
        $execs = $shell->execCalls;
        $this->assertTrue(collect($execs)->contains(
            fn ($call) => str_contains($call['command'], '/etc/systemd/system/')
                && str_contains($call['command'], $site->id)
        ));
        $this->assertTrue(collect($execs)->contains(
            fn ($call) => $call['command'] === 'sudo systemctl daemon-reload',
        ));
        $this->assertTrue(collect($execs)->contains(
            fn ($call) => str_contains($call['command'], 'systemctl enable --now')
                && str_contains($call['command'], $site->id),
        ));
    }

    public function test_provision_uploads_separate_units_for_non_web_processes(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
            'repository_path' => '/var/www/queue-app',
        ]);
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => 'npm start']);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        $shell = new RecordingShell;

        $written = (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
            ->provision($site, fn () => $shell);

        $this->assertCount(2, $written);
        $this->assertContains("dply-site-{$site->id}.service", $written);
        $this->assertContains("dply-site-{$site->id}-worker.service", $written);

        $contents = collect($shell->putFiles)->pluck('contents')->all();
        $this->assertTrue(collect($contents)->contains(fn ($c) => str_contains($c, 'ExecStart=npm start')));
        $this->assertTrue(collect($contents)->contains(fn ($c) => str_contains($c, 'ExecStart=npm run worker')));
    }

    public function test_provision_skips_php_sites_with_no_units_to_write(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'php',
            'start_command' => null,
        ]);

        $shell = new RecordingShell;

        $written = (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
            ->provision($site, fn () => $shell);

        $this->assertSame([], $written);
        $this->assertSame([], $shell->putFiles);
        $this->assertSame([], $shell->execCalls);
    }

    public function test_provision_throws_when_server_not_ready(): void
    {
        $server = Server::factory()->create([
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => null,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
            ->provision($site, fn () => new RecordingShell);
    }

    public function test_teardown_disables_and_removes_each_unit(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
        ]);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        $shell = new RecordingShell;
        $names = (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
            ->teardown($site, fn () => $shell);

        $this->assertContains("dply-site-{$site->id}.service", $names);
        $this->assertContains("dply-site-{$site->id}-worker.service", $names);

        $disableCalls = collect($shell->execCalls)
            ->filter(fn ($c) => str_contains($c['command'], 'systemctl disable'))
            ->count();
        $this->assertSame(2, $disableCalls);

        $this->assertTrue(collect($shell->execCalls)->contains(
            fn ($c) => $c['command'] === 'sudo systemctl daemon-reload'
        ));
    }
}

/**
 * In-memory RemoteShell that records putFile + exec calls so tests can
 * assert on the shell sequence without booting an SSH client.
 */
class RecordingShell implements RemoteShell
{
    /** @var list<array{path: string, contents: string}> */
    public array $putFiles = [];

    /** @var list<array{command: string, timeout: int}> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = ['command' => $command, 'timeout' => $timeoutSeconds];

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = ['path' => $remotePath, 'contents' => $contents];
    }
}
