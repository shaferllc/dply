<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\SshConnection;

class SupervisorProvisioner
{
    public function sync(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $ssh = new SshConnection($server);
        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $log = '';

        foreach ($server->supervisorPrograms()->where('is_active', true)->get() as $program) {
            /** @var SupervisorProgram $program */
            $ini = $this->buildIni($program);
            $path = $dir.'/dply-sv-'.$program->id.'.conf';
            $ssh->putFile($path, $ini);
            $log .= "Wrote {$path}\n";
        }

        $log .= $ssh->exec('supervisorctl reread 2>&1; supervisorctl update 2>&1; printf "\nDPLY_SV_EXIT:%s" "$?"', 180);

        return $log;
    }

    protected function buildIni(SupervisorProgram $program): string
    {
        $name = 'dply-sv-'.$program->id;
        $cmd = $program->command;
        $cwd = $program->directory;
        $user = $program->user ?: 'www-data';
        $num = max(1, (int) $program->numprocs);

        return <<<INI
; Managed by Dply
[program:{$name}]
command={$cmd}
directory={$cwd}
user={$user}
autostart=true
autorestart=true
numprocs={$num}
redirect_stderr=true
stdout_logfile=/tmp/dply-{$name}.log
stopwaitsecs=3600

INI;
    }
}
