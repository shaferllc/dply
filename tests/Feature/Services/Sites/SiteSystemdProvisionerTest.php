<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites\SiteSystemdProvisionerTest;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('provision uploads web unit and activates via systemctl', function () {
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

    expect($written)->toContain("dply-site-{$site->id}.service");
    $putFiles = $shell->putFiles;
    expect($putFiles)->toHaveCount(1);
    $this->assertStringContainsString('Environment=PORT=30007', $putFiles[0]['contents']);
    $this->assertStringContainsString('ExecStart=npm start', $putFiles[0]['contents']);

    // Verify the install + daemon-reload + enable sequence.
    $execs = $shell->execCalls;
    expect(collect($execs)->contains(
        fn ($call) => str_contains($call['command'], '/etc/systemd/system/')
            && str_contains($call['command'], $site->id)
    ))->toBeTrue();
    expect(collect($execs)->contains(
        fn ($call) => $call['command'] === 'sudo systemctl daemon-reload',
    ))->toBeTrue();
    expect(collect($execs)->contains(
        fn ($call) => str_contains($call['command'], 'systemctl enable --now')
            && str_contains($call['command'], $site->id),
    ))->toBeTrue();
});
test('provision uploads separate units for non web processes', function () {
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

    expect($written)->toHaveCount(2);
    expect($written)->toContain("dply-site-{$site->id}.service");
    expect($written)->toContain("dply-site-{$site->id}-worker.service");

    $contents = collect($shell->putFiles)->pluck('contents')->all();
    expect(collect($contents)->contains(fn ($c) => str_contains($c, 'ExecStart=npm start')))->toBeTrue();
    expect(collect($contents)->contains(fn ($c) => str_contains($c, 'ExecStart=npm run worker')))->toBeTrue();
});
test('provision skips php sites with no units to write', function () {
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

    expect($written)->toBe([]);
    expect($shell->putFiles)->toBe([]);
    expect($shell->execCalls)->toBe([]);
});
test('provision throws when server not ready', function () {
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
});
test('teardown unit disables and removes a single unit', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
    ]);

    $shell = new RecordingShell;
    (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
        ->teardownUnit($site, 'dply-site-'.$site->id.'-celery.service', fn () => $shell);

    $combined = implode("\n", array_column($shell->execCalls, 'command'));
    $this->assertStringContainsString('systemctl disable --now', $combined);
    $this->assertStringContainsString('rm -f /etc/systemd/system/', $combined);
    $this->assertStringContainsString('celery.service', $combined);
    $this->assertStringContainsString('daemon-reload', $combined);
});
test('teardown unit throws when server not ready', function () {
    $server = Server::factory()->create([
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => null,
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);

    (new SiteSystemdProvisioner(new SiteSystemdUnitBuilder))
        ->teardownUnit($site, 'dply-site-'.$site->id.'.service', fn () => new RecordingShell);
});
test('teardown disables and removes each unit', function () {
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

    expect($names)->toContain("dply-site-{$site->id}.service");
    expect($names)->toContain("dply-site-{$site->id}-worker.service");

    $disableCalls = collect($shell->execCalls)
        ->filter(fn ($c) => str_contains($c['command'], 'systemctl disable'))
        ->count();
    expect($disableCalls)->toBe(2);

    expect(collect($shell->execCalls)->contains(
        fn ($c) => $c['command'] === 'sudo systemctl daemon-reload'
    ))->toBeTrue();
});
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
