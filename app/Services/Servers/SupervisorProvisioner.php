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
        $log = $this->removeOrphanConfigsForServer($ssh, $server, $dir);

        $programs = $server->supervisorPrograms()->where('is_active', true)->get();
        if ($programs->isEmpty()) {
            $log .= "No active Supervisor programs configured.\n";
            $log .= $ssh->exec('supervisorctl reread 2>&1; supervisorctl update 2>&1; printf "\nDPLY_SV_EXIT:%s" "$?"', 180);

            return $log;
        }

        foreach ($programs as $program) {
            /** @var SupervisorProgram $program */
            $ini = $this->buildIni($program);
            $path = $dir.'/dply-sv-'.$program->id.'.conf';
            $ssh->putFile($path, $ini);
            $log .= "Wrote {$path}\n";
        }

        $log .= $ssh->exec('supervisorctl reread 2>&1; supervisorctl update 2>&1; printf "\nDPLY_SV_EXIT:%s" "$?"', 180);

        return $log;
    }

    /**
     * Remove conf files on the server for programs that no longer exist in the database for this server.
     */
    public function removeOrphanConfigsForServer(SshConnection $ssh, Server $server, ?string $confDir = null): string
    {
        $dir = $confDir ?? rtrim(config('sites.supervisor_conf_d'), '/');
        $validIds = $server->supervisorPrograms()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $validSpace = implode(' ', $validIds);
        $dirEsc = escapeshellarg($dir);

        $script = sprintf(
            'shopt -s nullglob; valid=" %s "; for f in %s/dply-sv-*.conf; do '.
            'id="${f##*-}"; id="${id%%.conf}"; case "$valid" in *" $id "*) ;; *) rm -f "$f"; echo "removed $f";; esac; done',
            $validSpace,
            $dirEsc
        );

        $out = $ssh->exec($script.' 2>&1', 60);

        return $out !== '' ? $out."\n" : '';
    }

    public function deleteConfigFile(Server $server, int $programId): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $ssh = new SshConnection($server);
        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $path = $dir.'/dply-sv-'.$programId.'.conf';
        $ssh->exec('rm -f '.escapeshellarg($path), 30);
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
