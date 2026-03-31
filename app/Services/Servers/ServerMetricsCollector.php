<?php

namespace App\Services\Servers;

use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Collects CPU, memory, disk, and load via python3 on the guest: prefers ~/.dply/bin/server-metrics-snapshot.py
 * when installed, otherwise the same script inlined from resources/server-scripts/server-metrics-snapshot.py.
 */
class ServerMetricsCollector
{
    private const METRICS_BEGIN_MARKER = 'DPLY_METRICS_JSON_BEGIN';

    private const METRICS_END_MARKER = 'DPLY_METRICS_JSON_END';

    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
        protected ServerMetricsRecorder $recorder,
    ) {}

    /**
     * Runs on the server SSH user (no root required for /proc, disk usage).
     *
     * @return array<string, mixed>
     */
    public function collect(Server $server): array
    {
        return $this->runCollection($server)['payload'];
    }

    /**
     * @return array{payload: array<string, mixed>, remote_script_sha: ?string}
     */
    protected function runCollection(Server $server): array
    {
        $out = $this->remote->runInlineBash(
            $server,
            'server-metrics-snapshot',
            self::metricsInlineBash(),
            45,
            false,
        );

        $buffer = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        $remoteScriptSha = $this->parseRemoteScriptShaFromBuffer($buffer);
        $jsonLine = $this->extractMetricsJsonLine($buffer);
        if ($jsonLine === null) {
            Log::warning('server_metrics.invalid_output', ['server_id' => $server->id, 'buffer' => mb_substr($buffer, 0, 2000)]);

            throw new \RuntimeException($this->metricsParseFailureMessage($buffer, $remoteScriptSha));
        }

        try {
            $decoded = json_decode($jsonLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(__('Invalid metrics JSON from server.').' '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(__('Invalid metrics payload from server.'));
        }

        if (($decoded['error'] ?? null) === 'python3_missing') {
            throw new \RuntimeException(__('python3 is required on the server to collect CPU, memory, and disk metrics. Install it with your package manager, then try again.'));
        }

        return [
            'payload' => $this->normalizePayload($decoded),
            'remote_script_sha' => $remoteScriptSha,
        ];
    }

    public function collectAndStore(Server $server): ServerMetricSnapshot
    {
        $result = $this->runCollection($server);
        $freshServer = $server->fresh();

        $this->syncCollectionMeta($freshServer, $result['remote_script_sha']);
        $this->maybeQueueGuestScriptUpgrade($freshServer->fresh(), $result['remote_script_sha']);

        $payload = $result['payload'];
        $snapshot = $this->recorder->storeSnapshot($freshServer->fresh(), $payload, now());

        app(ServerMetricsGuestPushService::class)->ensureConfigured($freshServer->fresh());

        return $snapshot;
    }

    protected function syncCollectionMeta(Server $server, ?string $remoteScriptSha): void
    {
        $meta = $server->meta ?? [];
        $meta['monitoring_collect_checked_at'] = now()->toIso8601String();
        $meta['monitoring_guest_script_sha'] = $remoteScriptSha;
        $server->forceFill(['meta' => $meta])->saveQuietly();
    }

    protected function maybeQueueGuestScriptUpgrade(Server $server, ?string $remoteScriptSha): void
    {
        if (! (bool) config('server_metrics.guest_script.auto_upgrade_on_collect', true)) {
            return;
        }

        if ($remoteScriptSha === null) {
            return;
        }

        $guest = app(ServerMetricsGuestScript::class);
        try {
            $bundled = $guest->bundledSha256();
        } catch (\Throwable) {
            return;
        }

        if ($remoteScriptSha === $bundled) {
            return;
        }

        UpgradeGuestMetricsScriptJob::dispatch($server->id, $bundled);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizePayload(array $raw): array
    {
        return [
            'cpu_pct' => $this->floatOrNull($raw['cpu_pct'] ?? null),
            'mem_pct' => $this->floatOrNull($raw['mem_pct'] ?? null),
            'disk_pct' => $this->floatOrNull($raw['disk_pct'] ?? null),
            'load_1m' => $this->floatOrNull($raw['load_1m'] ?? null),
            'load_5m' => $this->floatOrNull($raw['load_5m'] ?? null),
            'load_15m' => $this->floatOrNull($raw['load_15m'] ?? null),
            'mem_total_kb' => $this->intOrNull($raw['mem_total_kb'] ?? null),
            'mem_available_kb' => $this->intOrNull($raw['mem_available_kb'] ?? null),
            'swap_total_kb' => $this->intOrNull($raw['swap_total_kb'] ?? null),
            'swap_used_kb' => $this->intOrNull($raw['swap_used_kb'] ?? null),
            'disk_total_bytes' => $this->intOrNull($raw['disk_total_bytes'] ?? null),
            'disk_used_bytes' => $this->intOrNull($raw['disk_used_bytes'] ?? null),
            'disk_free_bytes' => $this->intOrNull($raw['disk_free_bytes'] ?? null),
            'inode_pct_root' => $this->floatOrNull($raw['inode_pct_root'] ?? null),
            'cpu_count' => $this->intOrNull($raw['cpu_count'] ?? null),
            'load_per_cpu_1m' => $this->floatOrNull($raw['load_per_cpu_1m'] ?? null),
            'uptime_seconds' => $this->intOrNull($raw['uptime_seconds'] ?? null),
            'rx_bytes_per_sec' => $this->floatOrNull($raw['rx_bytes_per_sec'] ?? null),
            'tx_bytes_per_sec' => $this->floatOrNull($raw['tx_bytes_per_sec'] ?? null),
        ];
    }

    protected function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    protected function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    protected function extractJsonLine(string $buffer): ?string
    {
        $lines = preg_split('/\R/u', $buffer) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && str_starts_with($line, '{')) {
                return $line;
            }
        }

        return null;
    }

    protected function extractMetricsJsonLine(string $buffer): ?string
    {
        $markedPayload = $this->extractMarkedPayload($buffer);

        return $this->extractJsonLine($markedPayload ?? $buffer);
    }

    protected function extractMarkedPayload(string $buffer): ?string
    {
        $lines = preg_split('/\R/u', $buffer) ?: [];
        $capturing = false;
        $captured = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === self::METRICS_BEGIN_MARKER) {
                $capturing = true;
                $captured = [];

                continue;
            }

            if ($trimmed === self::METRICS_END_MARKER) {
                return implode("\n", $captured);
            }

            if ($capturing) {
                $captured[] = $line;
            }
        }

        return null;
    }

    /**
     * First line of output may be DPLY_SCRIPT_SHA=&lt;64 hex|MISSING&gt; when the guest file exists or not.
     */
    protected function parseRemoteScriptShaFromBuffer(string $buffer): ?string
    {
        $lines = preg_split('/\R/u', $buffer) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'DPLY_SCRIPT_SHA=')) {
                $v = trim(substr($line, strlen('DPLY_SCRIPT_SHA=')));

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    protected function metricsParseFailureMessage(string $buffer, ?string $remoteScriptSha): string
    {
        $trimmed = trim($buffer);

        if ($trimmed === '') {
            return __('Could not collect metrics because the server returned no output over SSH.');
        }

        if ($remoteScriptSha === 'MISSING') {
            return __('Could not collect metrics because the monitor script is missing on the server. Use "Push latest monitor script" or reinstall monitoring, then try again.');
        }

        if (str_contains($trimmed, 'command not found')) {
            return __('Could not collect metrics because the remote shell reported a missing command. Verify Python 3 and standard shell tools are installed, then try again.');
        }

        if (preg_match('/permission denied/i', $trimmed) === 1) {
            return __('Could not collect metrics because the SSH user hit a permission error while running the monitor command.');
        }

        if ($this->extractMetricsJsonLine($buffer) === null) {
            return __('Could not collect metrics because the server returned shell output instead of metrics JSON. Verify the SSH login shell is quiet and that the monitor script can run cleanly.');
        }

        return __('Could not parse metrics from the server.');
    }

    protected static function metricsInlineBash(): string
    {
        $py = app(ServerMetricsGuestScript::class)->pythonBodyForInlineFallback();
        $eof = 'DPLY_METRICS_SNAPSHOT_EOF';
        $begin = self::METRICS_BEGIN_MARKER;
        $end = self::METRICS_END_MARKER;

        return <<<BASH
SCRIPT="\$HOME/.dply/bin/server-metrics-snapshot.py"
if [ -f "\$SCRIPT" ]; then
  echo "DPLY_SCRIPT_SHA=\$(sha256sum "\$SCRIPT" | awk '{print \$1}')"
else
  echo "DPLY_SCRIPT_SHA=MISSING"
fi
echo "{$begin}"
if [ -x "\$SCRIPT" ]; then
  env -i HOME="\$HOME" PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" python3 "\$SCRIPT"
elif command -v python3 >/dev/null 2>&1; then
  env -i HOME="\$HOME" PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" python3 <<'{$eof}'
{$py}
{$eof}
else
  echo '{"error":"python3_missing"}'
fi
echo "{$end}"
BASH;
    }
}
