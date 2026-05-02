<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Support\SupervisorEnvFormatter;

class SupervisorProvisioner
{
    /**
     * Whether the Debian/Ubuntu supervisor package is installed (dpkg).
     */
    public function isSupervisorPackageInstalled(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        try {
            $out = app(ServerSshConnectionRunner::class)->run(
                $server,
                fn ($ssh): string => $ssh->exec('dpkg-query -W -f=\'${Status}\' supervisor 2>/dev/null || true', 30),
                $this->useRootSsh(),
                $this->fallbackToDeployUserSsh()
            );
        } catch (\Throwable) {
            return false;
        }

        return str_contains($out, 'ok installed');
    }

    /**
     * apt install supervisor + enable service (runs as root SSH user, or sudo for deploy user).
     *
     * @throws \RuntimeException
     */
    public function installSupervisorPackage(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $inner = 'export DEBIAN_FRONTEND=noninteractive && apt-get update -y && apt-get install -y --no-install-recommends supervisor && systemctl enable --now supervisor';
        $cmd = $this->privilegedBash($server, $inner);

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($cmd.' 2>&1', 900),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    public function sync(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $packagePresent = $server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED
            || $this->isSupervisorPackageInstalled($server);
        if (! $packagePresent) {
            throw new \RuntimeException(
                'Supervisor is not installed on this server. Install it from Server → Daemons first.'
            );
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $programs = $server->supervisorPrograms()->where('is_active', true)->get();
        $rereadUpdate = $this->supervisorRereadUpdateExecLine($server);

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $dir, $programs, $rereadUpdate): string {
                $log = $this->removeOrphanConfigsForServer($ssh, $server, $dir);

                if ($programs->isEmpty()) {
                    $log .= "No active Supervisor programs configured.\n";
                    $log .= $ssh->exec($rereadUpdate, 180);

                    return $log;
                }

                foreach ($programs as $program) {
                    /** @var SupervisorProgram $program */
                    $ini = $this->buildIni($program);
                    $path = $dir.'/dply-sv-'.$program->id.'.conf';
                    $ssh->putFile($path, $ini);
                    $log .= "Wrote {$path}\n";
                }

                $log .= $ssh->exec($rereadUpdate, 180);

                return $log;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Dry-run: compare generated INI to files on disk (no writes).
     */
    public function previewSyncDiff(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        $programs = $server->supervisorPrograms()->where('is_active', true)->orderBy('slug')->get();
        if ($programs->isEmpty()) {
            return "No active programs — sync would only run supervisorctl reread/update.\n";
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($programs, $dir): string {
                $out = '';

                foreach ($programs as $program) {
                    $local = $this->buildIni($program);
                    $path = $dir.'/dply-sv-'.$program->id.'.conf';
                    $remote = $ssh->exec('if test -f '.escapeshellarg($path).' ; then cat '.escapeshellarg($path).'; else echo __DPLY_MISSING__; fi', 60);
                    $remote = trim((string) $remote);
                    $out .= "--- {$path} ---\n";
                    if ($remote === '__DPLY_MISSING__') {
                        $out .= "Remote: (file missing)\nLocal would be:\n".$local."\n";
                    } elseif ($remote === $local) {
                        $out .= "No difference.\n";
                    } else {
                        $out .= $this->unifiedDiffSnippet($remote, $local);
                    }
                }

                return $out;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * List Dply-managed conf files vs DB and flag orphan files on disk.
     */
    public function driftReport(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $ids = $server->supervisorPrograms()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $dirEsc = escapeshellarg($dir);
        $listRaw = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec('ls -1 '.$dirEsc.'/dply-sv-*.conf 2>/dev/null || true', 30)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
        $remoteIds = [];
        foreach (preg_split('/\r\n|\r|\n/', $listRaw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $base = basename($line, '.conf');
            if (str_starts_with($base, 'dply-sv-')) {
                $remoteIds[] = substr($base, strlen('dply-sv-'));
            }
        }

        $out = 'Database programs: '.implode(', ', $ids ?: ['(none)'])."\n";
        $out .= 'Remote dply-sv-*.conf IDs: '.implode(', ', $remoteIds ?: ['(none)'])."\n";

        foreach ($remoteIds as $rid) {
            if (! in_array($rid, $ids, true)) {
                $out .= "Orphan on server (not in DB): dply-sv-{$rid}.conf — sync or delete in UI may remove it.\n";
            }
        }
        foreach ($ids as $id) {
            if (! in_array($id, $remoteIds, true)) {
                $out .= "Missing on server: dply-sv-{$id}.conf — run Sync.\n";
            }
        }

        return $out;
    }

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
     * Raw supervisorctl status (for health checks).
     */
    public function fetchSupervisorctlStatus(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server): string {
                $sc = $this->supervisorctlInv($server);

                return trim((string) $ssh->exec($sc.' status 2>&1', 120));
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Multi-section Supervisor diagnostics — systemd status + supervisorctl version/status + tail
     * of the supervisord daemon log. Returns one combined log so the workspace can render it in
     * a single console panel even when no programs are configured (in which case `supervisorctl
     * status` is silent and the Inspect tab would otherwise look empty).
     */
    public function inspect(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $unit = (string) config('sites.supervisor_systemd_unit', 'supervisor');
        $unitEsc = escapeshellarg($unit);
        $systemctl = $this->privilegedBinaryPrefix($server, 'systemctl');
        $sc = $this->supervisorctlInv($server);
        $daemonLog = $this->supervisordDaemonLogPath();
        $tailCmd = $this->privilegedBinaryPrefix($server, 'tail').' -n 80 '.escapeshellarg($daemonLog).' 2>&1';

        $sections = [
            ['label' => 'systemctl is-active '.$unit, 'cmd' => $systemctl.' is-active '.$unitEsc.' 2>&1'],
            ['label' => 'systemctl status '.$unit.' (head)', 'cmd' => $systemctl.' status --no-pager '.$unitEsc.' 2>&1 | head -20'],
            ['label' => 'supervisorctl version', 'cmd' => $sc.' version 2>&1'],
            ['label' => 'supervisorctl status', 'cmd' => $sc.' status 2>&1'],
            ['label' => 'tail '.$daemonLog, 'cmd' => $tailCmd],
        ];

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($sections): string {
                $out = '';
                foreach ($sections as $entry) {
                    $out .= str_repeat('═', 60)."\n";
                    $out .= '$ '.$entry['label']."\n";
                    $out .= str_repeat('═', 60)."\n";
                    $body = trim((string) $ssh->exec($entry['cmd'], 60));
                    $out .= ($body === '' ? '(no output)' : $body)."\n\n";
                }

                return $out;
            },
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

    /**
     * Wrap a binary with `sudo -n env PATH=…` for non-root SSH users so privileged paths like
     * /usr/sbin (systemctl) and /var/log files owned by root are reachable. Matches the firewall
     * provisioner's pattern.
     */
    protected function privilegedBinaryPrefix(Server $server, string $binary): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return $binary;
        }

        return 'sudo -n env PATH=/usr/sbin:/usr/bin:/sbin:/bin '.$binary;
    }

    /**
     * Shell line for: supervisorctl reread; supervisorctl update (with sudo when SSH user is not root).
     * Deploy users often cannot access supervisord's socket without sudo — matches {@see privilegedBash}.
     */
    public function supervisorRereadUpdateExecLine(Server $server, string $exitLabel = 'DPLY_SV_EXIT'): string
    {
        $sc = $this->supervisorctlInv($server);

        return $sc.' reread 2>&1; '.$sc.' update 2>&1; printf "\n'.$exitLabel.':%s" "$?"';
    }

    /**
     * How to invoke supervisorctl over SSH: plain for root, {@code sudo -n supervisorctl} for other users.
     */
    protected function supervisorctlInv(Server $server): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return 'supervisorctl';
        }

        return 'sudo -n supervisorctl';
    }

    /**
     * Start/stop/restart the Supervisor system service (systemd), or query status / boot flags.
     * Uses {@see privilegedBash} so non-root SSH users run via passwordless sudo, like other server management.
     *
     * @param  string  $action  One of: status, start, stop, restart, reload, is-active, is-enabled, enable, disable
     */
    public function manageSupervisorService(Server $server, string $action): string
    {
        $allowed = ['status', 'start', 'stop', 'restart', 'reload', 'is-active', 'is-enabled', 'enable', 'disable'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid supervisor service action.');
        }
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $unit = (string) config('sites.supervisor_systemd_unit', 'supervisor');
        $unitEsc = escapeshellarg($unit);

        $inner = match ($action) {
            'is-active', 'is-enabled' => 'systemctl '.$action.' '.$unitEsc.' 2>&1; printf \'\nDPLY_EXIT:%s\' "$?"',
            default => '(systemctl '.$action.' '.$unitEsc.' 2>&1) || (service '.$unitEsc.' '.$action.' 2>&1); printf \'\nDPLY_EXIT:%s\' "$?"',
        };

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec($this->privilegedBash($server, $inner), 180)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * @return array{ok: bool, bad_lines: array<int, string>, burst_lines: array<int, string>, summary: string}
     */
    public function analyzeStatusForManagedPrograms(Server $server, string $statusOutput): array
    {
        $programs = $server->supervisorPrograms()->where('is_active', true)->get();
        $prefixes = $programs->map(fn (SupervisorProgram $p) => 'dply-sv-'.$p->id)->all();
        $bad = [];
        $burst = [];
        foreach (preg_split('/\r\n|\r|\n/', $statusOutput) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (! str_starts_with($line, $prefix)) {
                    continue;
                }
                if (preg_match('/\b(FATAL|BACKOFF|EXITED|UNKNOWN)\b/i', $line)) {
                    $bad[] = $line;
                }
                if (preg_match('/\bBACKOFF\b/i', $line)
                    || preg_match('/too quickly|spawn error|too many|gave up/i', $line)) {
                    $burst[] = $line;
                }
                break;
            }
        }
        $ok = $bad === [] && $burst === [];
        $parts = [];
        if ($bad !== []) {
            $parts[] = 'Some managed programs need attention: '.implode('; ', array_slice($bad, 0, 5));
        }
        if ($burst !== []) {
            $parts[] = 'Possible restart loop / backoff: '.implode('; ', array_slice(array_unique($burst), 0, 3));
        }
        $summary = $parts === []
            ? 'All managed programs report OK.'
            : implode(' ', $parts);

        return ['ok' => $ok, 'bad_lines' => $bad, 'burst_lines' => $burst, 'summary' => $summary];
    }

    /**
     * True if any generated program INI differs from the file on disk (or file missing).
     */
    public function hasConfigDrift(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }
        $programs = $server->supervisorPrograms()->where('is_active', true)->orderBy('slug')->get();
        if ($programs->isEmpty()) {
            return false;
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($programs, $dir): bool {
                foreach ($programs as $program) {
                    $local = $this->buildIni($program);
                    $path = $dir.'/dply-sv-'.$program->id.'.conf';
                    $remote = trim((string) $ssh->exec('if test -f '.escapeshellarg($path).' ; then cat '.escapeshellarg($path).'; else echo __DPLY_MISSING__; fi', 60));
                    if ($remote === '__DPLY_MISSING__' || $remote !== $local) {
                        return true;
                    }
                }

                return false;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * @return array{messages: array<int, string>, ok: bool}
     */
    public function preflightPathCheck(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['messages' => [__('Server must be ready with SSH.')], 'ok' => false];
        }
        $programs = $server->supervisorPrograms()->where('is_active', true)->orderBy('slug')->get();
        if ($programs->isEmpty()) {
            return ['messages' => [__('No active programs to check.')], 'ok' => true];
        }

        $messages = [];
        $messages = app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($programs): array {
                $messages = [];

                foreach ($programs as $program) {
                    $dir = $program->directory;
                    $check = trim((string) $ssh->exec('if test -d '.escapeshellarg($dir).' ; then echo ok; elif test -e '.escapeshellarg($dir).' ; then echo notdir; else echo missing; fi', 30));
                    if ($check !== 'ok') {
                        $messages[] = $check === 'missing'
                            ? __('Program :slug: working directory does not exist: :path', ['slug' => $program->slug, 'path' => $dir])
                            : __('Program :slug: working directory is not a directory: :path', ['slug' => $program->slug, 'path' => $dir]);
                    }
                }

                return $messages;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        return ['messages' => $messages, 'ok' => $messages === []];
    }

    /**
     * @return array<string, array{state: string, lines: array<int, string>}>
     */
    public function parseManagedProgramStatuses(Server $server, string $statusOutput): array
    {
        $programs = $server->supervisorPrograms()->get();
        $byId = [];
        foreach ($programs as $p) {
            $byId[$p->id] = ['state' => 'unknown', 'lines' => []];
        }

        foreach (preg_split('/\r\n|\r|\n/', $statusOutput) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            foreach ($programs as $p) {
                $prefix = 'dply-sv-'.$p->id;
                if (str_starts_with($line, $prefix)) {
                    $byId[$p->id]['lines'][] = $line;
                    $st = $this->classifySupervisorStatusLine($line);
                    $byId[$p->id]['state'] = $this->worstSupervisorState($byId[$p->id]['state'], $st);
                    break;
                }
            }
        }

        return $byId;
    }

    protected function classifySupervisorStatusLine(string $line): string
    {
        if (preg_match('/\bFATAL\b/i', $line)) {
            return 'fatal';
        }
        if (preg_match('/\bBACKOFF\b/i', $line)) {
            return 'backoff';
        }
        if (preg_match('/\bEXITED\b/i', $line)) {
            return 'exited';
        }
        if (preg_match('/\bSTOPPED\b/i', $line)) {
            return 'stopped';
        }
        if (preg_match('/\bSTARTING\b/i', $line)) {
            return 'starting';
        }
        if (preg_match('/\bRUNNING\b/i', $line)) {
            return 'running';
        }

        return 'unknown';
    }

    protected function worstSupervisorState(string $a, string $b): string
    {
        $rank = [
            'unknown' => 0,
            'running' => 1,
            'starting' => 2,
            'stopped' => 3,
            'exited' => 4,
            'backoff' => 5,
            'fatal' => 6,
        ];

        return ($rank[$b] ?? 0) >= ($rank[$a] ?? 0) ? $b : $a;
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
        $cwd = $program->directory;
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

    protected function useRootSsh(): bool
    {
        return (bool) config('server_services.use_root_ssh', true);
    }

    protected function fallbackToDeployUserSsh(): bool
    {
        return (bool) config('server_services.fallback_to_deploy_user_ssh', true);
    }
}
