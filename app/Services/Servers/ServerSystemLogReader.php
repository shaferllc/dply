<?php

namespace App\Services\Servers;

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WebhookDeliveryLog;
use App\Services\SshConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ServerSystemLogReader
{
    /**
     * @param  callable(string):void|null  $onStreamStart  Invoked with a human-readable command summary before execution.
     * @param  callable(string):void|null  $onOutputChunk  Invoked for each SSH output chunk when using phpseclib streaming.
     * @param  int|null  $tailLineCount  Override tail line count (clamped); null uses config default.
     * @param  int|null  $sinceMinutes  When set, restrict to recent window (dply DB, journal --since, file lines by timestamp heuristics).
     * @return array{output: string, error: ?string}
     */
    public function fetch(
        Server $server,
        string $key,
        ?callable $onStreamStart = null,
        ?callable $onOutputChunk = null,
        ?int $tailLineCount = null,
        ?int $sinceMinutes = null,
    ): array {
        $sources = config('server_system_logs.sources', []);
        $tailLimit = $this->resolveTailLineCount($tailLineCount, $sinceMinutes);

        if (isset($sources[$key])) {
            $def = $sources[$key];

            if (($def['type'] ?? 'file') === 'dply') {
                $output = $this->dplyActivityLog($server, $tailLimit, $sinceMinutes);
                if ($onStreamStart !== null) {
                    $onStreamStart(__('Dply audit log (database query)'));
                }
                if ($onOutputChunk !== null && $output !== '') {
                    $onOutputChunk($output);
                }

                return ['output' => $output, 'error' => null];
            }

            if (($def['type'] ?? 'file') === 'journal') {
                $unit = $this->resolveJournalUnit($server, $def);
                if ($unit === null) {
                    return ['output' => '', 'error' => __('Journal unit is not configured or not allowlisted.')];
                }

                if (! $server->ip_address || ! $server->ssh_private_key) {
                    return ['output' => '', 'error' => __('Server is not reachable over SSH yet.')];
                }

                return $this->journalOverSsh(
                    $server,
                    $unit,
                    $tailLimit,
                    $onStreamStart,
                    $onOutputChunk,
                    $sinceMinutes,
                );
            }

            $path = $this->resolvePath($server, (string) ($def['path'] ?? ''));
            if ($path === '' || str_contains($path, '..')) {
                return ['output' => '', 'error' => __('Invalid log path configuration.')];
            }

            if (! $this->pathMatchesAllowlist($path)) {
                return ['output' => '', 'error' => __('This log path is not allowlisted.')];
            }

            if (! $server->ip_address || ! $server->ssh_private_key) {
                return ['output' => '', 'error' => __('Server is not reachable over SSH yet.')];
            }

            return $this->tailFileOverSsh(
                $server,
                $path,
                $tailLimit,
                $onStreamStart,
                $onOutputChunk,
                $sinceMinutes,
            );
        }

        $platformSite = $this->resolveSiteForPlatformLog($server, $key);
        if ($platformSite !== null) {
            $output = $this->sitePlatformActivityLog($platformSite, $tailLimit, $sinceMinutes);
            if ($onStreamStart !== null) {
                $onStreamStart(__('Dply site activity (database query)'));
            }
            if ($onOutputChunk !== null && $output !== '') {
                $onOutputChunk($output);
            }

            return ['output' => $output, 'error' => null];
        }

        $sitePath = $this->resolveSitePerHostLogPath($server, $key);
        if ($sitePath !== null) {
            if (! $this->pathMatchesAllowlist($sitePath)) {
                return ['output' => '', 'error' => __('This log path is not allowlisted.')];
            }

            if (! $server->ip_address || ! $server->ssh_private_key) {
                return ['output' => '', 'error' => __('Server is not reachable over SSH yet.')];
            }

            return $this->tailFileOverSsh(
                $server,
                $sitePath,
                $tailLimit,
                $onStreamStart,
                $onOutputChunk,
                $sinceMinutes,
            );
        }

        return ['output' => '', 'error' => __('Unknown log source.')];
    }

    public function dplyActivityLog(Server $server, int $maxLines = 300, ?int $sinceMinutes = null): string
    {
        if ($server->organization_id === null) {
            return __('No organization — no activity log.');
        }

        $limit = max(1, min(5000, $maxLines));

        $siteIds = Site::query()->where('server_id', $server->id)->pluck('id');

        $logs = AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->where(function ($q) use ($server, $siteIds) {
                $q->where(function ($q2) use ($server) {
                    $q2->where('subject_type', Server::class)
                        ->where('subject_id', $server->id);
                });
                if ($siteIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($siteIds) {
                        $q2->where('subject_type', Site::class)
                            ->whereIn('subject_id', $siteIds);
                    });
                }
            })
            ->when($sinceMinutes !== null && $sinceMinutes > 0, function ($q) use ($sinceMinutes) {
                $q->where('created_at', '>=', Carbon::now()->subMinutes($sinceMinutes));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->with('user')
            ->get();

        if ($logs->isEmpty()) {
            return __('No Dply activity recorded for this server yet.');
        }

        $tz = config('app.timezone');
        $lines = [];
        foreach ($logs as $log) {
            $lines[] = sprintf(
                '%s  %s  %s  %s',
                $log->created_at->timezone($tz)->format('Y-m-d H:i:s'),
                $log->action,
                $log->user?->name ?? '—',
                Str::limit((string) ($log->subject_summary ?? ''), 120)
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Merges audit entries, deployments, and webhook delivery rows for one site (newest first).
     */
    public function sitePlatformActivityLog(Site $site, int $maxLines = 300, ?int $sinceMinutes = null): string
    {
        if ($site->organization_id === null) {
            return __('No organization — no activity log.');
        }

        $limit = max(1, min(5000, $maxLines));
        $tz = config('app.timezone');
        $since = ($sinceMinutes !== null && $sinceMinutes > 0)
            ? Carbon::now()->subMinutes($sinceMinutes)
            : null;

        $entries = [];

        $audits = AuditLog::query()
            ->where('organization_id', $site->organization_id)
            ->where('subject_type', Site::class)
            ->where('subject_id', $site->getKey())
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->with('user')
            ->get();

        foreach ($audits as $log) {
            $at = $log->created_at;
            $entries[] = [
                'at' => $at,
                'line' => sprintf(
                    '%s  audit  %s  %s  %s',
                    $at->timezone($tz)->format('Y-m-d H:i:s'),
                    $log->action,
                    $log->user?->name ?? '—',
                    Str::limit((string) ($log->subject_summary ?? ''), 120)
                ),
            ];
        }

        $deployments = SiteDeployment::query()
            ->where('site_id', $site->getKey())
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        foreach ($deployments as $deployment) {
            $at = $deployment->created_at ?? $deployment->started_at ?? Carbon::now();
            $entries[] = [
                'at' => $at,
                'line' => sprintf(
                    '%s  deploy  %s · %s  %s  %s',
                    $at->timezone($tz)->format('Y-m-d H:i:s'),
                    $deployment->trigger,
                    $deployment->status,
                    $deployment->git_sha !== null && $deployment->git_sha !== ''
                        ? Str::limit($deployment->git_sha, 12)
                        : '—',
                    Str::limit((string) ($deployment->log_output ?? ''), 100)
                ),
            ];
        }

        $webhooks = WebhookDeliveryLog::query()
            ->where('site_id', $site->getKey())
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        foreach ($webhooks as $webhook) {
            $at = $webhook->created_at ?? Carbon::now();
            $entries[] = [
                'at' => $at,
                'line' => sprintf(
                    '%s  webhook  %s · %s  %s  %s',
                    $at->timezone($tz)->format('Y-m-d H:i:s'),
                    $webhook->http_status,
                    $webhook->outcome,
                    $webhook->request_ip ?? '—',
                    Str::limit((string) ($webhook->detail ?? ''), 120)
                ),
            ];
        }

        if ($entries === []) {
            return __('No platform activity recorded for this site yet.');
        }

        usort($entries, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        $lines = [];
        foreach (array_slice($entries, 0, $limit) as $row) {
            $lines[] = $row['line'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  callable(string):void|null  $onStreamStart
     * @param  callable(string):void|null  $onOutputChunk
     * @return array{output: string, error: ?string}
     */
    private function tailFileOverSsh(
        Server $server,
        string $path,
        int $lines,
        ?callable $onStreamStart,
        ?callable $onOutputChunk,
        ?int $sinceMinutes = null,
    ): array {
        $qPath = escapeshellarg($path);
        $script = "if [ -r {$qPath} ]; then tail -n {$lines} {$qPath} 2>&1; ".
            "elif [ -f {$qPath} ]; then echo '[dply] File exists but is not readable as this SSH user.'; ".
            "else echo '[dply] File not found or path is not a regular file.'; fi";
        $cmd = '/bin/sh -c '.escapeshellarg($script);
        $budget = (int) config('server_system_logs.request_time_budget_seconds', 90);
        $sshTimeout = $budget > 0 ? max(15, min(60, $budget - 5)) : 60;

        $candidates = $this->sshLoginCandidatesForLogPath($path, $server);
        $lastError = null;
        $fallback = (bool) config('server_system_logs.fallback_to_deploy_user_for_logs', true);

        foreach ($candidates as $i => $loginUser) {
            if ($onStreamStart !== null) {
                $onStreamStart(sprintf('%s@%s  %s', $loginUser, $server->ip_address, $cmd));
            }

            try {
                if ($onOutputChunk !== null && $i > 0) {
                    $onOutputChunk("\n\n--- ".sprintf(__('Retry as %s'), $loginUser)." ---\n\n");
                }

                $ssh = new SshConnection($server, $loginUser);
                if ($onOutputChunk !== null) {
                    $output = $ssh->execWithCallback($cmd, $onOutputChunk, $sshTimeout);
                } else {
                    $output = $ssh->exec($cmd, $sshTimeout);
                }
                $ssh->disconnect();

                $unreadable = str_contains($output, '[dply] File exists but is not readable as this SSH user.');
                if ($unreadable && $fallback && $i < count($candidates) - 1) {
                    continue;
                }

                $output = $this->reverseLogLinesNewestFirst($output);

                if ($sinceMinutes !== null && $sinceMinutes > 0) {
                    $output = LogViewerTimeRange::filterLines($output, $sinceMinutes);
                    if ($output !== '' && ! str_starts_with($output, '[dply]')) {
                        $output = '[dply] '.sprintf(__('Time range: last %d minutes (best effort).'), $sinceMinutes)."\n\n".$output;
                    }
                }

                if ($i > 0) {
                    $output = '[dply] '.sprintf(__('Read using SSH user %s.'), $loginUser)."\n\n".$output;
                }

                return ['output' => $output, 'error' => null];
            } catch (\Throwable $e) {
                $lastError = $e;

                continue;
            }
        }

        return [
            'output' => '',
            'error' => $lastError !== null ? $lastError->getMessage() : __('SSH connection failed for all login candidates.'),
        ];
    }

    /**
     * @param  callable(string):void|null  $onStreamStart
     * @param  callable(string):void|null  $onOutputChunk
     * @return array{output: string, error: ?string}
     */
    private function journalOverSsh(
        Server $server,
        string $unit,
        int $lines,
        ?callable $onStreamStart,
        ?callable $onOutputChunk,
        ?int $sinceMinutes = null,
    ): array {
        $n = max(50, min(5000, $lines));
        $qUnit = escapeshellarg($unit);
        $sinceArg = ($sinceMinutes !== null && $sinceMinutes > 0)
            ? ' --since '.escapeshellarg('-'.$sinceMinutes.' minutes')
            : '';
        $script = "journalctl -u {$qUnit}{$sinceArg} -n {$n} --no-pager -o short-iso 2>&1 || echo '[dply] journalctl failed or unit not found.'";
        $cmd = '/bin/sh -c '.escapeshellarg($script);
        $budget = (int) config('server_system_logs.request_time_budget_seconds', 90);
        $sshTimeout = $budget > 0 ? max(15, min(60, $budget - 5)) : 60;

        $candidates = $this->sshLoginCandidatesForLogPath('/var/log/syslog', $server);
        $lastError = null;

        foreach ($candidates as $i => $loginUser) {
            if ($onStreamStart !== null) {
                $onStreamStart(sprintf('%s@%s  %s', $loginUser, $server->ip_address, $cmd));
            }

            try {
                if ($onOutputChunk !== null && $i > 0) {
                    $onOutputChunk("\n\n--- ".sprintf(__('Retry as %s'), $loginUser)." ---\n\n");
                }

                $ssh = new SshConnection($server, $loginUser);
                if ($onOutputChunk !== null) {
                    $output = $ssh->execWithCallback($cmd, $onOutputChunk, $sshTimeout);
                } else {
                    $output = $ssh->exec($cmd, $sshTimeout);
                }
                $ssh->disconnect();

                $output = $this->reverseLogLinesNewestFirst($output);

                if ($i > 0) {
                    $output = '[dply] '.sprintf(__('Read using SSH user %s.'), $loginUser)."\n\n".$output;
                }

                return ['output' => $output, 'error' => null];
            } catch (\Throwable $e) {
                $lastError = $e;

                continue;
            }
        }

        return [
            'output' => '',
            'error' => $lastError !== null ? $lastError->getMessage() : __('SSH connection failed for all login candidates.'),
        ];
    }

    private function resolveJournalUnit(Server $server, array $def): ?string
    {
        $unit = '';
        if (! empty($def['unit'])) {
            $unit = trim((string) $def['unit']);
        } elseif (! empty($def['unit_template'])) {
            $unit = trim(str_replace(
                '{php_version}',
                $this->phpVersionForServer($server),
                (string) $def['unit_template']
            ));
        }

        if ($unit === '') {
            return null;
        }

        return $this->journalUnitIfAllowlisted($unit);
    }

    private function journalUnitIfAllowlisted(string $unit): ?string
    {
        foreach (config('server_system_logs.journal_allowed_units', []) as $candidate) {
            if (is_string($candidate) && strcasecmp($candidate, $unit) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSiteForPlatformLog(Server $server, string $key): ?Site
    {
        if (! preg_match('/^site_([0-9A-HJKMNP-TV-Z]{26})_platform$/i', $key, $m)) {
            return null;
        }

        $siteId = strtolower($m[1]);

        return Site::query()
            ->where('server_id', $server->id)
            ->whereKey($siteId)
            ->first();
    }

    private function resolveSitePerHostLogPath(Server $server, string $key): ?string
    {
        if (! preg_match('/^site_([0-9A-HJKMNP-TV-Z]{26})_(access|error)$/i', $key, $m)) {
            return null;
        }

        $siteId = strtolower($m[1]);
        $which = strtolower($m[2]);

        $site = Site::query()
            ->where('server_id', $server->id)
            ->whereKey($siteId)
            ->first();

        if ($site === null) {
            return null;
        }

        $basename = $site->webserverConfigBasename();
        if (! preg_match('/^[a-zA-Z0-9._\-]+$/', $basename)) {
            return null;
        }

        $suffix = $which === 'access' ? '-access.log' : '-error.log';

        return $site->webserverLogDirectory().'/'.$basename.$suffix;
    }

    /**
     * @return list<string>
     */
    private function sshLoginCandidatesForLogPath(string $path, Server $server): array
    {
        $deploy = trim((string) $server->ssh_user);
        if ($deploy === '') {
            $deploy = 'root';
        }

        $asRoot = (bool) config('server_system_logs.read_system_log_paths_as_root', true);
        $fallback = (bool) config('server_system_logs.fallback_to_deploy_user_for_logs', true);

        $systemPath = str_starts_with($path, '/var/log/') || str_starts_with($path, '/root/');

        if (! $systemPath || ! $asRoot) {
            return [$deploy];
        }

        if ($deploy === 'root') {
            return ['root'];
        }

        $order = ['root', $deploy];

        return $fallback ? $order : ['root'];
    }

    private function resolvePath(Server $server, string $path): string
    {
        $path = str_replace('{php_version}', $this->phpVersionForServer($server), $path);
        $path = str_replace('{ssh_user}', $server->ssh_user ?? 'www-data', $path);

        return $path;
    }

    private function phpVersionForServer(Server $server): string
    {
        $fromMeta = $server->meta['default_php_version'] ?? null;
        if (is_string($fromMeta) && preg_match('/^\d+(\.\d+)?$/', $fromMeta)) {
            return $fromMeta;
        }

        $site = Site::query()
            ->where('server_id', $server->id)
            ->whereNotNull('php_version')
            ->orderByDesc('updated_at')
            ->first();

        if ($site && is_string($site->php_version) && preg_match('/^\d+(\.\d+)?$/', $site->php_version)) {
            return $site->php_version;
        }

        return (string) config('server_system_logs.default_php_version', '8.3');
    }

    private function pathMatchesAllowlist(string $path): bool
    {
        $prefixes = config('server_system_logs.allowed_path_prefixes', []);

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveTailLineCount(?int $requested, ?int $sinceMinutes = null): int
    {
        $default = (int) config('server_system_logs.tail_lines', 500);
        $n = $requested ?? $default;

        $n = max(50, min(5000, $n));

        if ($sinceMinutes !== null && $sinceMinutes > 0) {
            $floor = (int) config('server_system_logs.time_range_min_tail_lines', 2500);

            return max($n, min(5000, $floor));
        }

        return $n;
    }

    /**
     * tail(1) prints the last N lines in file order (oldest of that window first). Reverse so the newest line is on top.
     */
    private function reverseLogLinesNewestFirst(string $text): string
    {
        $text = rtrim($text, "\n");
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            return $text;
        }

        return implode("\n", array_reverse($lines));
    }
}
