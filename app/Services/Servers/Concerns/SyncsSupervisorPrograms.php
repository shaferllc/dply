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
trait SyncsSupervisorPrograms
{


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
}
