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
        $programs = $server->supervisorPrograms()->with('site')->where('is_active', true)->get();
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
                    $log .= $this->writeConfFile($ssh, $server, $path, $ini);
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

    /**
     * Re-register a single Dply-managed program on the host: (re)write its
     * supervisor conf file, then `supervisorctl reread && supervisorctl update`
     * so supervisord learns the program. This is the remediation for programs
     * that exist in Dply but are "NOT REPORTED" by supervisorctl (the conf was
     * never applied, or was removed on the box) and therefore fail start/stop
     * with "no such process". Reuses the same conf path and reread/update line
     * as {@see sync()} — this is sync() scoped to one program.
     */
    public function syncProgram(Server $server, string $programId): string
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

        $program = SupervisorProgram::query()->where('server_id', $server->id)->whereKey($programId)->first();
        if (! $program) {
            throw new \RuntimeException('Program not found.');
        }
        if (! $program->is_active) {
            throw new \RuntimeException('This program is inactive — activate it before syncing.');
        }

        return $this->syncProgramResult($server, $programId)['log'];
    }

    /**
     * Re-register a single program AND ensure it is actually started, capturing
     * + classifying the real outcome so the UI can show registered / running /
     * FATAL-with-reason instead of a silent no-op.
     *
     * Why an explicit start: `supervisorctl update` only (re)starts a group when
     * its config changed; if the program was already known to supervisord (or
     * `update` adds it but the autostart spawn fails) it can sit STOPPED/FATAL.
     * We therefore run reread → update → start → status and parse the status
     * line. `exec()` ignores exit codes in this codebase, so we rely on the
     * stdout/stderr text (and the trailing status line) to know what happened.
     *
     * @return array{log: string, state: string, status_line: string, message: string, ok: bool}
     */
    public function syncProgramResult(Server $server, string $programId): array
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

        $program = SupervisorProgram::query()->where('server_id', $server->id)->whereKey($programId)->first();
        if (! $program) {
            throw new \RuntimeException('Program not found.');
        }
        if (! $program->is_active) {
            throw new \RuntimeException('This program is inactive — activate it before syncing.');
        }

        $dir = rtrim(config('sites.supervisor_conf_d'), '/');
        $rereadUpdate = $this->supervisorRereadUpdateExecLine($server);
        $group = 'dply-sv-'.$program->id;

        $raw = app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $program, $dir, $rereadUpdate, $group): array {
                $ini = $this->buildIni($program);
                $path = $dir.'/dply-sv-'.$program->id.'.conf';

                // Write the conf into the include dir. If the SSH user can't write
                // there directly (root-owned /etc/supervisor/conf.d), surface it
                // rather than letting reread silently not see the file.
                try {
                    $writeLog = $this->writeConfFile($ssh, $server, $path, $ini);
                } catch (\Throwable $e) {
                    $writeLog = "Failed to write {$path}: ".$e->getMessage()."\n";
                }

                $log = $writeLog;
                $log .= $ssh->exec($rereadUpdate, 180)."\n";

                $sc = $this->supervisorctlInv($server);
                // Explicitly (re)start; `update` alone may leave it STOPPED/FATAL.
                $startOut = trim((string) $ssh->exec($sc.' start '.escapeshellarg($group).' 2>&1', 120));
                $log .= '$ supervisorctl start '.$group."\n".$startOut."\n";

                // Re-probe this program's status line so the result reflects reality.
                $statusOut = trim((string) $ssh->exec($sc.' status '.escapeshellarg($group).' 2>&1', 60));
                $log .= '$ supervisorctl status '.$group."\n".$statusOut."\n";

                return ['log' => $log, 'start' => $startOut, 'status' => $statusOut];
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        return $this->classifySyncProgramResult($group, $raw['log'], $raw['start'], $raw['status']);
    }

    /**
     * Turn the reread/update/start/status text into a single clear outcome.
     *
     * @return array{log: string, state: string, status_line: string, message: string, ok: bool}
     */
    protected function classifySyncProgramResult(string $group, string $log, string $startOut, string $statusOut): array
    {
        $state = $this->classifySupervisorStatusLine($statusOut);
        $alreadyStarted = (bool) preg_match('/already started/i', $startOut);

        // A status line like "no such process/group" means the conf still isn't
        // registered — the write or reread didn't take (path/permissions).
        if ($this->indicatesUnregisteredProgram($statusOut)) {
            return [
                'log' => $log,
                'state' => 'unknown',
                'status_line' => $statusOut,
                'message' => 'Supervisor still does not see this program after reread/update. The conf file may not be in supervisord’s include directory, or could not be written. Check Inspect for details.',
                'ok' => false,
            ];
        }

        if ($state === 'running') {
            return [
                'log' => $log,
                'state' => $state,
                'status_line' => $statusOut,
                'message' => $alreadyStarted
                    ? 'Re-registered. Program was already running.'
                    : 'Re-registered and started — program is RUNNING.',
                'ok' => true,
            ];
        }

        if (in_array($state, ['fatal', 'backoff', 'exited'], true)) {
            $reason = $this->extractSpawnFailureReason($statusOut.' '.$startOut);

            return [
                'log' => $log,
                'state' => $state,
                'status_line' => $statusOut,
                'message' => 'Re-registered but the program failed to stay up ('.strtoupper($state).'). '
                    .($reason !== '' ? $reason.' ' : '')
                    .'Check the command, the working directory, and the run-as user, then view the program logs.',
                'ok' => false,
            ];
        }

        // starting/stopped/unknown but registered.
        return [
            'log' => $log,
            'state' => $state === 'unknown' ? 'starting' : $state,
            'status_line' => $statusOut,
            'message' => 'Re-registered on the server. Current state: '.strtoupper($state === 'unknown' ? 'starting' : $state).'.',
            'ok' => true,
        ];
    }

    /**
     * Pull a human-readable cause out of supervisorctl start/status text for
     * common spawn failures (bad command/dir/user, exited too quickly).
     */
    protected function extractSpawnFailureReason(string $text): string
    {
        if (preg_match('/spawn error/i', $text)) {
            return 'Spawn error — supervisord could not exec the command (check the binary path, directory, and user).';
        }
        if (preg_match('/too quickly|exited too quickly/i', $text)) {
            return 'The process exited too quickly to be considered started (it likely errored on launch).';
        }
        if (preg_match('/(?:ENOENT|No such file or directory)/i', $text)) {
            return 'A path does not exist (command or working directory).';
        }
        if (preg_match('/Permission denied/i', $text)) {
            return 'Permission denied (the run-as user may not be able to run the command or enter the directory).';
        }

        return '';
    }

    /**
     * Whether a supervisorctl output/error string indicates the program group
     * is unknown to supervisord (config never applied / removed on the box).
     * Drives the friendly "not registered — use Sync" message instead of leaking
     * the raw `ERROR (no such process)`.
     */
    public function indicatesUnregisteredProgram(string $output): bool
    {
        return (bool) preg_match('/no such (process|group)/i', $output);
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
