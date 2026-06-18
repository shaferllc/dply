<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\Concerns\ControlsSupervisorPrograms;
use App\Services\Servers\Concerns\ManagesSupervisorPackage;
use App\Services\Servers\Concerns\ReadsSupervisorLogs;
use App\Services\Servers\Concerns\ReadsSupervisorStatus;
use App\Services\Servers\Concerns\SyncsSupervisorPrograms;
use App\Services\Servers\Concerns\WritesSupervisorConfig;
use App\Support\SupervisorEnvFormatter;

class SupervisorProvisioner
{
    use ControlsSupervisorPrograms;
    use ManagesSupervisorPackage;
    use ReadsSupervisorLogs;
    use ReadsSupervisorStatus;
    use SyncsSupervisorPrograms;
    use WritesSupervisorConfig;



    /**
     * Run a bash -lc script as root, or via passwordless sudo when SSH user is not root.
     */
    protected function privilegedBash(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);
        $wrapped = 'bash -lc '.escapeshellarg($command);
        if ($user === '' || $user === 'root') {
            return $wrapped;
        }

        return 'sudo -n '.$wrapped;
    }


    protected function useRootSsh(): bool
    {
        return (bool) config('server_services.use_root_ssh', true);
    }

    protected function fallbackToDeployUserSsh(): bool
    {
        return (bool) config('server_services.fallback_to_deploy_user_ssh', true);
    }
}
