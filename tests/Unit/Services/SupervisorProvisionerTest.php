<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SupervisorProvisionerTest;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

/**
 * Bind a fake ServerSshConnectionRunner whose run() invokes the drift callback
 * with a stub $ssh that returns $remoteContent for the cat/test-f probe.
 */
function fakeDriftSsh(string $remoteContent): void
{
    $ssh = new class($remoteContent)
    {
        public function __construct(public string $remote) {}

        public function exec($cmd, $timeout = 60): string
        {
            return $this->remote;
        }

        public function disconnect(): void {}
    };

    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $runner->shouldReceive('run')
        ->andReturnUsing(fn (Server $server, callable $callback) => $callback($ssh, 'root'));
    app()->instance(ServerSshConnectionRunner::class, $runner);
}

function driftServerWithProgram(): array
{
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'k']);
    $program = SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'queue-default',
        'program_type' => 'site',
        'command' => 'php /var/www/app/artisan queue:work',
        'directory' => '/var/www/app',
        'user' => 'www-data',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    return [$server, $program];
}

test('build ini includes expert fields when set', function () {
    $p = new SupervisorProgram([
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'command' => 'php artisan horizon',
        'directory' => '/var/www/app/current',
        'user' => 'www-data',
        'numprocs' => 1,
        'priority' => 100,
        'startsecs' => 2,
        'stopwaitsecs' => 120,
        'autorestart' => 'unexpected',
        'redirect_stderr' => false,
        'stderr_logfile' => '/tmp/e.log',
    ]);
    $p->exists = true;

    $ini = (new SupervisorProvisioner)->buildIni($p);

    $this->assertStringContainsString('priority=100', $ini);
    $this->assertStringContainsString('startsecs=2', $ini);
    $this->assertStringContainsString('stopwaitsecs=120', $ini);
    $this->assertStringContainsString('autorestart=unexpected', $ini);
    $this->assertStringContainsString('redirect_stderr=false', $ini);
    $this->assertStringContainsString('stderr_logfile=/tmp/e.log', $ini);
});
test('has config drift is false when remote matches generated ini modulo trailing newline', function () {
    [$server, $program] = driftServerWithProgram();
    $provisioner = new SupervisorProvisioner;

    // On disk the conf carries the trailing newline buildIni() emits; the SSH
    // read used to trim it and compare against the un-trimmed generated string,
    // flagging drift on every check. Trimming both sides must report no drift.
    fakeDriftSsh($provisioner->buildIni($program)."\n");

    expect($provisioner->hasConfigDrift($server->fresh()))->toBeFalse();
});
test('has config drift is true when remote content actually differs', function () {
    [$server, $program] = driftServerWithProgram();
    $provisioner = new SupervisorProvisioner;

    fakeDriftSsh(str_replace('queue:work', 'queue:work --tampered', $provisioner->buildIni($program)));

    expect($provisioner->hasConfigDrift($server->fresh()))->toBeTrue();
});
test('has config drift is true when remote file is missing', function () {
    [$server] = driftServerWithProgram();
    $provisioner = new SupervisorProvisioner;

    fakeDriftSsh('__DPLY_MISSING__');

    expect($provisioner->hasConfigDrift($server->fresh()))->toBeTrue();
});
test('analyze status detects burst lines', function () {
    $server = Server::factory()->create();
    $prog = SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'site_id' => null,
        'slug' => 'worker',
        'program_type' => 'queue',
        'command' => 'php artisan queue:work',
        'directory' => '/var/www',
        'user' => 'www-data',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $out = 'dply-sv-'.$prog->id.':dply-sv-'.$prog->id.'   BACKOFF  Exited too quickly (process log may have details)';

    $analysis = (new SupervisorProvisioner)->analyzeStatusForManagedPrograms($server->fresh(), $out);

    expect($analysis['ok'])->toBeFalse();
    expect($analysis['burst_lines'])->not->toBeEmpty();
});
test('manage supervisor service rejects invalid action', function () {
    $server = Server::factory()->make();
    $this->expectException(\InvalidArgumentException::class);
    (new SupervisorProvisioner)->manageSupervisorService($server, 'invalid');
});
