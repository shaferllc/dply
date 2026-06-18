<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Support\SupervisorEnvFormatter;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait WritesSupervisorConfig
{


    /**
     * Write a Supervisor program conf into the (root-owned) include dir reliably.
     *
     * SFTP putFile runs AS THE SSH USER, so when dply connects as a non-root
     * deploy user it cannot write /etc/supervisor/conf.d directly — the file
     * silently never lands and supervisorctl then reports "no such process"
     * (the program looks like it "didn't install"). For non-root users we stage
     * the file in a writable temp path and move it into place with
     * `sudo -n install` (a harmless no-op elevation for root SSH users, which
     * keep using a direct putFile). Returns a log line; on a sudo/install
     * failure the line is marked so the caller surfaces it instead of pretending
     * the write succeeded.
     */
    protected function writeConfFile($ssh, Server $server, string $path, string $contents): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            $ssh->putFile($path, $contents);

            return "Wrote {$path}\n";
        }

        $tmp = '/tmp/'.basename($path);
        $ssh->putFile($tmp, $contents);
        $out = trim((string) $ssh->exec(
            'sudo -n install -o root -g root -m 0644 '.escapeshellarg($tmp).' '.escapeshellarg($path).' 2>&1; '
            .'printf "DPLY_CONF_EXIT:%s" "$?"; rm -f '.escapeshellarg($tmp),
            60
        ));

        if (! str_contains($out, 'DPLY_CONF_EXIT:0')) {
            return "Failed to install {$path} as root (sudo): ".$out."\n";
        }

        return "Wrote {$path}\n";
    }

    /**
     * Remove conf files on the server for programs that no longer exist in the database for this server.
     */
    public function removeOrphanConfigsForServer($ssh, Server $server, ?string $confDir = null): string
    {
        $dir = $confDir ?? rtrim(config('sites.supervisor_conf_d'), '/');
        $validIds = $server->supervisorPrograms()->pluck('id')->map(fn ($id) => (string) $id)->all();
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

    public function deleteConfigFile(Server $server, string $programId): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $path = $dir.'/dply-sv-'.$programId.'.conf';
        app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($path): null {
                $ssh->exec('rm -f '.escapeshellarg($path), 30);

                return null;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    public function buildIni(SupervisorProgram $program): string
    {
        $name = 'dply-sv-'.$program->id;
        $cmd = $program->command;
        // Resolve the working dir from the site for site-scoped programs so an
        // imported/stale stored path (e.g. a Forge "apps/<x>/current" that does
        // not exist on a dply box) can't make supervisord FATAL on a bad chdir.
        $cwd = $program->effectiveDirectory();
        $user = $program->user ?: 'www-data';
        $num = max(1, (int) $program->numprocs);
        $logPath = $program->stdout_logfile !== null && $program->stdout_logfile !== ''
            ? $program->stdout_logfile
            : '/tmp/dply-'.$name.'.log';

        $startsecs = $program->startsecs !== null ? max(0, (int) $program->startsecs) : 1;
        $stopwait = $program->stopwaitsecs !== null ? max(0, (int) $program->stopwaitsecs) : 3600;
        $autorestart = $program->autorestart !== null && $program->autorestart !== ''
            ? $program->autorestart
            : 'true';
        $redirectStderr = $program->redirect_stderr ?? true;
        $redirectStderrIni = $redirectStderr ? 'true' : 'false';

        $env = is_array($program->env_vars) ? $program->env_vars : [];
        $envBlock = $env !== [] ? SupervisorEnvFormatter::toIniFragment($env) : '';

        $ini = <<<INI
; Managed by Dply
[program:{$name}]
command={$cmd}
directory={$cwd}
user={$user}
autostart=true
autorestart={$autorestart}
numprocs={$num}
startsecs={$startsecs}
redirect_stderr={$redirectStderrIni}
stdout_logfile={$logPath}

INI;
        if ($program->priority !== null) {
            $ini .= 'priority='.(int) $program->priority."\n";
        }
        if (! $redirectStderr) {
            $stderrPath = $program->stderr_logfile !== null && $program->stderr_logfile !== ''
                ? $program->stderr_logfile
                : '/tmp/dply-'.$name.'-stderr.log';
            $ini .= 'stderr_logfile='.$stderrPath."\n";
        }
        if ($envBlock !== '') {
            $ini .= $envBlock;
        }
        $ini .= 'stopwaitsecs='.$stopwait."\n";

        return $ini;
    }

    protected function unifiedDiffSnippet(string $a, string $b): string
    {
        if ($a === $b) {
            return "No difference.\n";
        }
        $la = explode("\n", $a);
        $lb = explode("\n", $b);
        $out = "Differences (remote vs local):\n";
        $max = max(count($la), count($lb));
        for ($i = 0; $i < $max; $i++) {
            $ra = $la[$i] ?? '';
            $rb = $lb[$i] ?? '';
            if ($ra !== $rb) {
                $out .= sprintf("%4d R: %s\n", $i + 1, $ra);
                $out .= sprintf("%4d L: %s\n", $i + 1, $rb);
            }
        }

        return $out;
    }
}
