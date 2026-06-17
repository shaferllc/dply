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
trait ReadsSupervisorStatus
{


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
     * @return array{ok: bool, bad_lines: array<int, string>, burst_lines: array<int, string>, summary: string}
     */
    /** @return array<string, mixed> */
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
     * @return array{messages: array<int, string>, ok: bool}
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
}
