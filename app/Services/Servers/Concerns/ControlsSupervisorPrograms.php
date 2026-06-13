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
trait ControlsSupervisorPrograms
{


    public function restartProgramGroup(Server $server, string $programId): string
    {
        return $this->supervisorctlProgramAction($server, $programId, 'restart');
    }

    public function stopProgramGroup(Server $server, string $programId): string
    {
        return $this->supervisorctlProgramAction($server, $programId, 'stop');
    }

    public function startProgramGroup(Server $server, string $programId): string
    {
        return $this->supervisorctlProgramAction($server, $programId, 'start');
    }

    protected function supervisorctlProgramAction(Server $server, string $programId, string $verb): string
    {
        if (! in_array($verb, ['restart', 'stop', 'start'], true)) {
            throw new \InvalidArgumentException('Invalid supervisorctl action.');
        }
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        $prog = SupervisorProgram::query()->where('server_id', $server->id)->whereKey($programId)->first();
        if (! $prog) {
            throw new \RuntimeException('Program not found.');
        }
        $group = 'dply-sv-'.$prog->id;

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $verb, $group): string {
                $sc = $this->supervisorctlInv($server);

                return trim($ssh->exec($sc.' '.$verb.' '.escapeshellarg($group).' 2>&1', 120));
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Restart all active Dply-managed program groups on this server.
     */
    public function restartAllManagedPrograms(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        $programs = $server->supervisorPrograms()->where('is_active', true)->get();
        if ($programs->isEmpty()) {
            return 'No active programs to restart.';
        }

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $programs): string {
                $sc = $this->supervisorctlInv($server);
                $chunks = [];
                foreach ($programs as $p) {
                    $group = 'dply-sv-'.$p->id;
                    $chunks[] = trim((string) $ssh->exec($sc.' restart '.escapeshellarg($group).' 2>&1', 120));
                }

                return implode("\n", $chunks);
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }
}
