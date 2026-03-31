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
        $jsonLine = $this->extractJsonLine($buffer);
        if ($jsonLine === null) {
            Log::warning('server_metrics.invalid_output', ['server_id' => $server->id, 'buffer' => mb_substr($buffer, 0, 2000)]);

            throw new \RuntimeException(__('Could not parse metrics from the server. Ensure python3 is installed, then try again.'));
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
        $this->maybeQueueGuestScriptUpgrade($server->fresh(), $result['remote_script_sha']);

        $payload = $result['payload'];
        $snapshot = $this->recorder->storeSnapshot($server->fresh(), $payload, now());

        app(ServerMetricsGuestPushService::class)->ensureConfigured($server->fresh());

        return $snapshot;
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
            'mem_total_kb' => isset($raw['mem_total_kb']) ? (int) $raw['mem_total_kb'] : null,
            'disk_total_bytes' => isset($raw['disk_total_bytes']) ? (int) $raw['disk_total_bytes'] : null,
            'disk_used_bytes' => isset($raw['disk_used_bytes']) ? (int) $raw['disk_used_bytes'] : null,
        ];
    }

    protected function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? round((float) $v, 2) : null;
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

    protected static function metricsInlineBash(): string
    {
        $py = app(ServerMetricsGuestScript::class)->pythonBodyForInlineFallback();
        $eof = 'DPLY_METRICS_SNAPSHOT_EOF';

        return <<<BASH
SCRIPT="\$HOME/.dply/bin/server-metrics-snapshot.py"
if [ -f "\$SCRIPT" ]; then
  echo "DPLY_SCRIPT_SHA=\$(sha256sum "\$SCRIPT" | awk '{print \$1}')"
else
  echo "DPLY_SCRIPT_SHA=MISSING"
fi
if [ -x "\$SCRIPT" ]; then
  python3 "\$SCRIPT"
elif command -v python3 >/dev/null 2>&1; then
  python3 <<'{$eof}'
{$py}
{$eof}
else
  echo '{"error":"python3_missing"}'
fi
BASH;
    }
}
