<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerSshConnectionRunner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ReadsSupervisorLogs
{


    public function tailProgramStdoutLog(Server $server, SupervisorProgram $program, int $lines = 200): string
    {
        return $this->tailProgramLogFile($server, $program, 'stdout', $lines);
    }

    public function tailProgramStderrLog(Server $server, SupervisorProgram $program, int $lines = 200): string
    {
        return $this->tailProgramLogFile($server, $program, 'stderr', $lines);
    }

    protected function tailProgramLogFile(Server $server, SupervisorProgram $program, string $which, int $lines): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        $name = 'dply-sv-'.$program->id;
        $defaultStdout = '/tmp/dply-'.$name.'.log';
        if ($which === 'stdout') {
            $path = $program->stdout_logfile !== null && $program->stdout_logfile !== ''
                ? $program->stdout_logfile
                : $defaultStdout;
        } else {
            $path = $program->stderr_logfile !== null && $program->stderr_logfile !== ''
                ? $program->stderr_logfile
                : $defaultStdout;
        }
        $lines = max(10, min(2000, $lines));

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec('tail -n '.(int) $lines.' '.escapeshellarg($path).' 2>&1', 60)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Tail the supervisord *daemon* log (not a program's stdout/stderr). This is where supervisord
     * itself logs startup, config reloads, and child-process spawn failures.
     */
    public function tailSupervisordDaemonLog(Server $server, int $lines = 200): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        $lines = max(10, min(2000, $lines));
        $path = $this->supervisordDaemonLogPath();
        $tailCmd = $this->privilegedBinaryPrefix($server, 'tail').' -n '.(int) $lines.' '.escapeshellarg($path).' 2>&1';

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec($tailCmd, 60)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Path to the supervisord daemon log. Debian/Ubuntu writes here by default; if the user has
     * customised `logfile=` in /etc/supervisor/supervisord.conf this won't match, but the tail
     * just returns "No such file or directory" which is a clear-enough hint.
     */
    protected function supervisordDaemonLogPath(): string
    {
        return (string) config('sites.supervisor_daemon_log', '/var/log/supervisor/supervisord.log');
    }
}
