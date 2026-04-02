<?php

namespace App\Livewire\Servers\Concerns;

use App\Events\Servers\ServerWorkspaceLogSnapshotBroadcast;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\Site;
use App\Services\Servers\ServerSystemLogReader;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

trait ManagesServerSystemLogs
{
    use StreamsRemoteSshLivewire;

    public string $logKey = 'dply_activity';

    public string $logFilter = '';

    public bool $logFilterUseRegex = false;

    public bool $logFilterInvert = false;

    /** Client-side validation message for invalid regex (when regex mode is on). */
    public ?string $logFilterError = null;

    public bool $logShowLineNumbers = false;

    /** Poll server for new log lines (same mechanism as “follow”). */
    public bool $logAutoRefresh = false;

    /** Allowed: 15, 30, 60. */
    public int $logAutoRefreshSeconds = 30;

    public int $logTotalLines = 0;

    public int $logFilteredLines = 0;

    public ?string $logLastFetchedAt = null;

    public bool $logLastFetchTruncated = false;

    public int $logLastFetchRawBytes = 0;

    /** Unix timestamp: skip polls until this time after failures. */
    public ?int $logPollBackoffUntil = null;

    public int $logPollFailureStreak = 0;

    /** Tail line count for file sources (SSH) and Dply activity query (50–5000). Stored on server meta. */
    public int $logTailLines = 500;

    /** Visible lines in log viewers before scrolling (2–50). Stored on server meta. */
    public int $logDisplayLines = 18;

    public ?string $remoteLogRaw = null;

    public ?string $remoteLogOutput = null;

    public ?string $remoteLogError = null;

    /** null = no time filter; 5 / 15 / 60 = minutes */
    public ?int $logTimeRangeMinutes = null;

    /** When set, the log viewer only lists this site’s platform + access/error sources. */
    public ?Site $scopedSite = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function availableLogSources(): array
    {
        $sources = config('server_system_logs.sources', []);
        $server = $this->server ?? null;
        if ($server === null) {
            return $sources;
        }

        if ($this->scopedSite !== null) {
            $site = $this->scopedSite;
            $id = (string) $site->getKey();
            $logDirectory = $site->webserverLogDirectory();
            $basename = $site->webserverConfigBasename();

            return [
                'site_'.$id.'_platform' => [
                    'type' => 'dply_site',
                    'label' => __('Platform activity'),
                    'group' => 'site',
                ],
                'site_'.$id.'_access' => [
                    'type' => 'file',
                    'label' => __('Site access log'),
                    'path' => $logDirectory.'/'.$basename.'-access.log',
                    'group' => 'site',
                ],
                'site_'.$id.'_error' => [
                    'type' => 'file',
                    'label' => __('Site error log'),
                    'path' => $logDirectory.'/'.$basename.'-error.log',
                    'group' => 'site',
                ],
            ];
        }

        $server->loadMissing('sites');

        foreach ($server->sites as $site) {
            $id = (string) $site->getKey();
            $logDirectory = $site->webserverLogDirectory();
            $basename = $site->webserverConfigBasename();
            $sources['site_'.$id.'_access'] = [
                'type' => 'file',
                'label' => __('Site access: :name', ['name' => $site->name]),
                'path' => $logDirectory.'/'.$basename.'-access.log',
                'group' => 'sites',
            ];
            $sources['site_'.$id.'_error'] = [
                'type' => 'file',
                'label' => __('Site error: :name', ['name' => $site->name]),
                'path' => $logDirectory.'/'.$basename.'-error.log',
                'group' => 'sites',
            ];
        }

        return $sources;
    }

    /**
     * Whether Echo may subscribe to this server's log channel (Reverb + policy).
     */
    public function logBroadcastEchoSubscribable(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('view', $this->server)) {
            return false;
        }

        if ($this->scopedSite !== null && ! $user->can('view', $this->scopedSite)) {
            return false;
        }

        $this->server->loadMissing('organization');

        if ($this->server->organization_id && $this->server->organization?->userIsDeployer($user)) {
            return false;
        }

        if (config('broadcasting.default') === 'null') {
            return false;
        }

        if (! config('broadcasting.echo_client_enabled', true)) {
            return false;
        }

        return filled(config('broadcasting.connections.reverb.key'));
    }

    public function selectLogSource(string $key): void
    {
        $this->authorize('view', $this->server);
        if ($this->scopedSite !== null) {
            $this->authorize('view', $this->scopedSite);
        }

        $keys = array_keys($this->availableLogSources());
        if (! in_array($key, $keys, true)) {
            return;
        }

        $this->logKey = $key;
        $this->loadSystemLog();
    }

    public function updatedLogFilter(): void
    {
        $this->applyLogFilterToOutput();
    }

    public function updatedLogFilterUseRegex(): void
    {
        $this->applyLogFilterToOutput();
    }

    public function updatedLogFilterInvert(): void
    {
        $this->applyLogFilterToOutput();
    }

    public function updatedLogShowLineNumbers(): void
    {
        $this->applyLogFilterToOutput();
    }

    public function refreshSystemLog(): void
    {
        $this->loadSystemLog();
    }

    /**
     * Livewire poll target: refresh when auto-refresh is on, with backoff after errors.
     */
    public function pollLogViewerRefresh(): void
    {
        if (! $this->logAutoRefresh) {
            return;
        }

        if ($this->logPollBackoffUntil !== null && time() < $this->logPollBackoffUntil) {
            return;
        }

        $this->loadSystemLog(fromPoll: true);
    }

    /**
     * Clear the in-browser log buffer and streamed SSH panel. Does not truncate or delete files on the server.
     */
    public function clearLogDisplay(): void
    {
        $this->authorize('view', $this->server);
        if ($this->scopedSite !== null) {
            $this->authorize('view', $this->scopedSite);
        }

        $this->remoteLogRaw = '';
        $this->remoteLogOutput = '';
        $this->remoteLogError = null;
        $this->logTotalLines = 0;
        $this->logFilteredLines = 0;
        $this->logLastFetchedAt = null;
        $this->logLastFetchTruncated = false;
        $this->logLastFetchRawBytes = 0;
        $this->logPollBackoffUntil = null;
        $this->logPollFailureStreak = 0;
        $this->resetRemoteSshStreamTargets();
    }

    public function applyLogTailLines(): void
    {
        $this->authorize('update', $this->server);
        if ($this->scopedSite !== null) {
            $this->authorize('view', $this->scopedSite);
        }

        $n = max(50, min(5000, (int) $this->logTailLines));
        $this->logTailLines = $n;

        $visible = max(2, min(50, (int) $this->logDisplayLines));
        $this->logDisplayLines = $visible;

        $refreshSeconds = in_array((int) $this->logAutoRefreshSeconds, [15, 30, 60], true)
            ? (int) $this->logAutoRefreshSeconds
            : 30;
        $this->logAutoRefreshSeconds = $refreshSeconds;

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['log_ui_tail_lines'] = $n;
        $meta['log_ui_display_lines'] = $visible;
        $meta['log_ui_auto_refresh'] = $this->logAutoRefresh;
        $meta['log_ui_auto_refresh_seconds'] = $refreshSeconds;
        $meta['log_ui_time_range_minutes'] = $this->logTimeRangeMinutes;
        $this->server->update(['meta' => $meta]);

        $this->logPollBackoffUntil = null;
        $this->logPollFailureStreak = 0;

        $this->loadSystemLog();
    }

    public function loadSystemLog(bool $fromPoll = false): void
    {
        $this->authorize('view', $this->server);
        if ($this->scopedSite !== null) {
            $this->authorize('view', $this->scopedSite);
        }

        if (! $fromPoll) {
            $this->logPollBackoffUntil = null;
            $this->logPollFailureStreak = 0;
        }

        $keys = array_keys($this->availableLogSources());
        if ($keys === []) {
            $this->remoteLogRaw = '';
            $this->remoteLogOutput = '';
            $this->remoteLogError = __('No log sources are configured.');

            return;
        }

        if (! in_array($this->logKey, $keys, true)) {
            $this->logKey = $keys[0];
        }

        $def = $this->availableLogSources()[$this->logKey] ?? [];
        $type = (string) ($def['type'] ?? 'file');
        $isDbOnly = in_array($type, ['dply', 'dply_site'], true);

        if (! $isDbOnly) {
            if (auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user())) {
                $this->remoteLogRaw = null;
                $this->remoteLogOutput = '';
                $this->remoteLogError = __('Deployers cannot read server log files over SSH.');

                return;
            }

            if (! $this->server->isReady() || ! $this->server->ssh_private_key) {
                $this->remoteLogRaw = null;
                $this->remoteLogOutput = '';
                $this->remoteLogError = __('Provisioning and SSH must be ready before reading files on the server.');

                return;
            }
        }

        $budget = (int) config('server_system_logs.request_time_budget_seconds', 90);
        if ($budget > 0) {
            @set_time_limit($budget);
        }

        $lockTtl = max(
            (int) config('server_system_logs.fetch_lock_seconds', 120),
            $budget > 0 ? $budget + 30 : 120,
        );
        $siteLockSegment = $this->scopedSite !== null ? (string) $this->scopedSite->getKey() : '0';
        $lock = Cache::lock('log-viewer-fetch:'.(string) $this->server->id.':'.$siteLockSegment.':'.$this->logKey, $lockTtl);
        $waitSeconds = $fromPoll
            ? max(1, (int) config('server_system_logs.fetch_lock_wait_poll_seconds', 10))
            : max(1, (int) config('server_system_logs.fetch_lock_wait_manual_seconds', 15));

        try {
            $lock->block($waitSeconds);
        } catch (LockTimeoutException) {
            if ($fromPoll) {
                $this->clearRemoteLogLockContentionError();
            } else {
                $this->remoteLogError = __('Another log fetch is in progress. Try again in a few seconds.');
            }

            return;
        }

        try {
            $this->resetRemoteSshStreamTargets();
            $reader = app(ServerSystemLogReader::class);
            $result = $reader->fetch(
                $this->server->fresh(),
                $this->logKey,
                function (string $summary): void {
                    $this->remoteSshStreamSetMeta(__('Remote command'), $summary);
                },
                function (string $chunk): void {
                    $this->remoteSshStreamAppendStdout($chunk);
                },
                $this->logTailLines,
                $this->logTimeRangeMinutes,
            );

            $raw = (string) ($result['output'] ?? '');
            $maxStored = (int) config('server_system_logs.max_stored_bytes', 524288);
            $truncated = false;
            if ($maxStored > 0 && strlen($raw) > $maxStored) {
                $truncated = true;
                $raw = substr($raw, 0, $maxStored);
                $raw = '[dply] '.__('Output truncated for the UI.')."\n\n".$raw;
            }

            $this->remoteLogRaw = $raw;
            $this->remoteLogError = $result['error'] ?? null;
            $this->logLastFetchTruncated = $truncated;
            $this->logLastFetchRawBytes = strlen($this->remoteLogRaw ?? '');
            $this->logLastFetchedAt = now()->toIso8601String();
            $this->applyLogFilterToOutput();
            $this->dispatch('log-viewer-output-updated');
            $this->broadcastLogSnapshotToPeers();

            if ($fromPoll) {
                $this->registerLogPollResult($this->remoteLogError === null);
            }
        } catch (\Throwable $e) {
            $this->remoteLogRaw = '';
            $this->remoteLogOutput = '';
            $this->remoteLogError = $e->getMessage();
            $this->logLastFetchedAt = now()->toIso8601String();
            $this->broadcastLogSnapshotToPeers();
            if ($fromPoll) {
                $this->registerLogPollResult(false);
            }
        } finally {
            $lock->release();
        }
    }

    public function loadSystemLogIfEmpty(): void
    {
        if ($this->remoteLogRaw !== null) {
            return;
        }

        $lockMsg = __('Another log fetch is in progress. Try again in a few seconds.');
        if ($this->remoteLogError !== null && $this->remoteLogError !== $lockMsg) {
            return;
        }

        $this->loadSystemLog();
    }

    protected function bootServerLogs(): void
    {
        $logKeys = array_keys($this->availableLogSources());
        if (! in_array($this->logKey, $logKeys, true)) {
            $this->logKey = $logKeys[0] ?? ($this->scopedSite !== null ? 'site_'.$this->scopedSite->getKey().'_platform' : 'dply_activity');
        }

        $this->syncLogViewerPreferencesFromServer();
        $this->loadSystemLog();
    }

    protected function syncLogViewerPreferencesFromServer(): void
    {
        $defaultTail = max(50, min(5000, (int) config('server_system_logs.tail_lines', 500)));
        $defaultDisplay = max(2, min(50, (int) config('server_system_logs.display_lines', 18)));
        $meta = $this->server->meta ?? [];

        $storedTail = $meta['log_ui_tail_lines'] ?? null;
        if (is_numeric($storedTail)) {
            $this->logTailLines = max(50, min(5000, (int) $storedTail));
        } else {
            $this->logTailLines = $defaultTail;
        }

        $storedDisplay = $meta['log_ui_display_lines'] ?? null;
        if (is_numeric($storedDisplay)) {
            $this->logDisplayLines = max(2, min(50, (int) $storedDisplay));
        } else {
            $this->logDisplayLines = $defaultDisplay;
        }

        if (isset($meta['log_ui_auto_refresh'])) {
            $this->logAutoRefresh = (bool) $meta['log_ui_auto_refresh'];
        }

        $storedRefresh = $meta['log_ui_auto_refresh_seconds'] ?? null;
        if (is_numeric($storedRefresh) && in_array((int) $storedRefresh, [15, 30, 60], true)) {
            $this->logAutoRefreshSeconds = (int) $storedRefresh;
        } else {
            $this->logAutoRefreshSeconds = 30;
        }

        $tr = $meta['log_ui_time_range_minutes'] ?? null;
        if ($tr === null || $tr === 'all' || $tr === '') {
            $this->logTimeRangeMinutes = null;
        } elseif (is_numeric($tr) && in_array((int) $tr, [5, 15, 60], true)) {
            $this->logTimeRangeMinutes = (int) $tr;
        } else {
            $this->logTimeRangeMinutes = null;
        }
    }

    public function setLogTimeRange(?int $minutes): void
    {
        $this->authorize('view', $this->server);
        if ($this->scopedSite !== null) {
            $this->authorize('view', $this->scopedSite);
        }

        if ($minutes !== null && ! in_array($minutes, [5, 15, 60], true)) {
            $minutes = null;
        }

        $this->logTimeRangeMinutes = $minutes;
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['log_ui_time_range_minutes'] = $minutes;
        $this->server->update(['meta' => $meta]);

        $this->loadSystemLog();
    }

    /** @param  ''|'5'|'15'|'60'  $value */
    public function setLogTimeRangeFromSelect(string $value): void
    {
        $minutes = match ($value) {
            '5' => 5,
            '15' => 15,
            '60' => 60,
            default => null,
        };

        $this->setLogTimeRange($minutes);
    }

    protected function registerLogPollResult(bool $success): void
    {
        if ($success) {
            $this->logPollFailureStreak = 0;
            $this->logPollBackoffUntil = null;

            return;
        }

        $this->logPollFailureStreak = min($this->logPollFailureStreak + 1, 6);
        $delay = (int) min(120, 2 ** $this->logPollFailureStreak);
        $this->logPollBackoffUntil = time() + max(5, $delay);
    }

    /**
     * Avoid leaving the lock-contention banner up when a poll skips: the next successful fetch clears
     * real errors; overlapping requests no longer strand the UI on a stale message.
     */
    protected function clearRemoteLogLockContentionError(): void
    {
        $msg = __('Another log fetch is in progress. Try again in a few seconds.');
        if ($this->remoteLogError === $msg) {
            $this->remoteLogError = null;
        }
    }

    protected function mergeRemoteLogFromBroadcast(array $payload): void
    {
        $user = auth()->user();
        if ($user !== null && $this->server->organization_id && $this->server->organization?->userIsDeployer($user)) {
            return;
        }

        if (($payload['server_id'] ?? '') !== (string) $this->server->id) {
            return;
        }

        $rawSiteId = $payload['site_id'] ?? null;
        $payloadSiteId = is_string($rawSiteId) && $rawSiteId !== '' ? $rawSiteId : null;

        if ($this->scopedSite === null) {
            if ($payloadSiteId !== null) {
                return;
            }
        } elseif ($payloadSiteId !== (string) $this->scopedSite->getKey()) {
            return;
        }

        if (($payload['log_key'] ?? '') !== $this->logKey) {
            return;
        }

        if (array_key_exists('remote_log_raw', $payload)) {
            $r = $payload['remote_log_raw'];
            $this->remoteLogRaw = $r === null ? null : (string) $r;
        }

        $err = $payload['remote_log_error'] ?? null;
        $this->remoteLogError = $err === null || is_string($err) ? $err : null;
        $this->logLastFetchedAt = isset($payload['log_last_fetched_at']) ? (string) $payload['log_last_fetched_at'] : null;
        $this->logLastFetchTruncated = (bool) ($payload['log_last_fetch_truncated'] ?? false);
        $this->logLastFetchRawBytes = (int) ($payload['log_last_fetch_raw_bytes'] ?? 0);

        if (! empty($payload['broadcast_payload_truncated'])) {
            $this->logLastFetchTruncated = true;
        }

        $this->applyLogFilterToOutput();
        $this->dispatch('log-viewer-output-updated');
    }

    protected function broadcastLogSnapshotToPeers(): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        if (! config('broadcasting.echo_client_enabled', true)) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $server = $this->server->fresh();
        if ($server->organization_id && $server->organization?->userIsDeployer($user)) {
            return;
        }

        $raw = $this->remoteLogRaw;
        $maxBroadcast = max(1024, (int) config('server_system_logs.max_broadcast_bytes', 131072));
        $broadcastPayloadTruncated = false;
        if (is_string($raw) && strlen($raw) > $maxBroadcast) {
            $raw = substr($raw, 0, $maxBroadcast);
            $broadcastPayloadTruncated = true;
        }

        broadcast(new ServerWorkspaceLogSnapshotBroadcast(
            serverId: (string) $server->id,
            logKey: $this->logKey,
            remoteLogRaw: is_string($raw) ? $raw : null,
            remoteLogError: $this->remoteLogError,
            logLastFetchedAt: (string) ($this->logLastFetchedAt ?? now()->toIso8601String()),
            logLastFetchTruncated: $this->logLastFetchTruncated,
            logLastFetchRawBytes: $this->logLastFetchRawBytes,
            broadcastPayloadTruncated: $broadcastPayloadTruncated,
            siteId: $this->scopedSite !== null ? (string) $this->scopedSite->getKey() : null,
        ))->toOthers();
    }

    private function applyLogFilterToOutput(): void
    {
        $this->logFilterError = null;

        $raw = $this->remoteLogRaw;
        if ($raw === null || $raw === '') {
            $this->remoteLogOutput = $raw ?? '';
            $this->logTotalLines = 0;
            $this->logFilteredLines = 0;

            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if ($lines === false) {
            $lines = [$raw];
        }

        $this->logTotalLines = count($lines);

        $f = trim($this->logFilter);
        if ($f === '') {
            $filtered = $lines;
        } elseif ($this->logFilterUseRegex) {
            $pattern = $this->buildSafeRegexPattern($f);
            if ($pattern === null) {
                $this->logFilterError = __('Invalid regular expression.');
                $filtered = $lines;
            } else {
                $filtered = array_values(array_filter($lines, function (string $line) use ($pattern): bool {
                    $ok = @preg_match($pattern, $line) === 1;
                    if (preg_last_error() !== PREG_NO_ERROR) {
                        return false;
                    }

                    return $this->logFilterInvert ? ! $ok : $ok;
                }));
            }
        } else {
            $needle = mb_strtolower($f);
            $filtered = array_values(array_filter($lines, function (string $line) use ($needle): bool {
                $ok = str_contains(mb_strtolower($line), $needle);

                return $this->logFilterInvert ? ! $ok : $ok;
            }));
        }

        $this->logFilteredLines = count($filtered);

        $body = implode("\n", $filtered);

        if ($this->logShowLineNumbers && $body !== '') {
            $width = max(2, strlen((string) count($filtered)));
            $numbered = [];
            foreach ($filtered as $i => $line) {
                $numbered[] = sprintf('%'.$width.'d  %s', $i + 1, $line);
            }
            $this->remoteLogOutput = implode("\n", $numbered);
        } else {
            $this->remoteLogOutput = $body;
        }
    }

    private function buildSafeRegexPattern(string $body): ?string
    {
        $delimiter = "\x01";
        if (str_contains($body, $delimiter)) {
            return null;
        }

        $pattern = $delimiter.$body.$delimiter.'iu';

        try {
            if (@preg_match($pattern, '') === false) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        if (preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $pattern;
    }
}
