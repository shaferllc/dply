<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Services\Servers\ServerManageScriptQueuer;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use App\Services\Servers\ServerMonitoringProbeQueuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * REST surface for a server's metrics agent: its state, its samples, and the
 * three operations the Metrics workspace can start.
 *
 * Exists so an external consumer can read agent state + snapshots and start
 * the write operations over REST instead of dispatching SSH jobs directly.
 */
class ServerMonitoringController extends Controller
{
    /** Snapshot rows per request. A 1-minute agent cadence makes 1000 ≈ 17 hours. */
    private const DEFAULT_SNAPSHOT_LIMIT = 1000;

    private const MAX_SNAPSHOT_LIMIT = 5000;

    /**
     * Agent state + recent samples in one round-trip.
     */
    public function show(Request $request, Server $server, ServerMetricsGuestPushVerifier $verifier): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_SNAPSHOT_LIMIT],
            'since' => ['sometimes', 'date'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_SNAPSHOT_LIMIT);

        $snapshots = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->when(
                isset($validated['since']),
                fn ($query) => $query->where('captured_at', '>=', Carbon::parse((string) $validated['since'])),
            )
            // Newest first for the limit, then handed back oldest-first so a
            // consumer can append without re-sorting.
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get(['captured_at', 'payload'])
            ->sortBy('captured_at')
            ->values()
            ->map(fn (ServerMetricSnapshot $snapshot): array => [
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
                'payload' => $snapshot->payload ?? [],
            ])
            ->all();

        return response()->json([
            'data' => [
                'monitoring' => $this->monitoringMeta($server),
                'thresholds' => $this->thresholdsPayload($server),
                // The verifier is pure meta arithmetic (no SSH), so this is the
                // same verdict the owning control plane's own page renders.
                'guest_push' => $verifier->summary($server),
                'snapshots' => $snapshots,
            ],
        ]);
    }

    /**
     * Queue the SSH probe (python3 / reachability check) on this control plane.
     */
    public function probe(Request $request, Server $server, ServerMonitoringProbeQueuer $queuer): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        if (! $this->opsReady($server)) {
            return response()->json([
                'message' => __('Provisioning and SSH must be ready before probing this server.'),
            ], 422);
        }

        $queued = $queuer->queue($server);

        return response()->json([
            'data' => [
                'queued' => $queued,
                'monitoring' => $this->monitoringMeta($server->refresh()),
            ],
        ], $queued ? 202 : 200);
    }

    /**
     * Queue the monitoring-agent install (apt python3 + metrics script).
     */
    public function install(Request $request, Server $server, ServerManageScriptQueuer $queuer): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        if (! $this->opsReady($server)) {
            return response()->json([
                'message' => __('Provisioning and SSH must be ready before installing packages.'),
            ], 422);
        }

        $key = 'install_monitoring_prerequisites';
        $definition = config('server_services.install_actions', [])[$key] ?? null;
        if (! is_array($definition) || empty($definition['script'])) {
            return response()->json(['message' => __('Unknown install action.')], 422);
        }

        $taskId = $queuer->queue(
            $server,
            'services-install:'.$key,
            (string) $definition['script'],
            isset($definition['timeout']) ? (int) $definition['timeout'] : null,
            ($definition['label'] ?? $key).' '.__('finished.'),
            is_string($definition['label'] ?? null) ? $definition['label'] : null,
            $request->user()?->id,
        );

        return response()->json(['data' => ['task_id' => $taskId]], 202);
    }

    /**
     * Persist per-server alert thresholds (meta only — no SSH).
     */
    public function thresholds(Request $request, Server $server): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $validated = $request->validate([
            'cpu' => ['required', 'numeric', 'min:1', 'max:99'],
            'mem' => ['required', 'numeric', 'min:1', 'max:99'],
            'load' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ]);

        $meta = $server->meta ?? [];
        $meta['metric_thresholds'] = [
            'cpu_warn_pct' => (float) $validated['cpu'],
            'mem_warn_pct' => (float) $validated['mem'],
            'load_warn' => (float) $validated['load'],
        ];
        $server->update(['meta' => $meta]);

        return response()->json(['data' => ['thresholds' => $this->thresholdsPayload($server->refresh())]]);
    }

    /**
     * The agent-state meta the Metrics page reads.
     *
     * An allowlist, not a `monitoring_*` sweep with secrets filtered out: the
     * bag holds the encrypted guest push token (`monitoring_guest_push_cipher`)
     * alongside the state flags, so anything not named here stays home — and a
     * key added upstream tomorrow is withheld by default rather than exported by
     * default. The two token keys are answered as a boolean instead; nothing on
     * the consumer side asks more than "is one present".
     */
    private const MONITORING_META_KEYS = [
        'monitoring_ssh_reachable',
        'monitoring_python_installed',
        'monitoring_probe_at',
        'monitoring_probe_pending',
        'monitoring_probe_error',
        'monitoring_last_sample_at',
        'monitoring_callback_env_deployed',
        'monitoring_callback_env_deployed_at',
        'monitoring_callback_env_present_remote',
        'monitoring_guest_cron_installed_at',
        'monitoring_guest_cron_present_remote',
        'monitoring_guest_push_cron_expression',
        'monitoring_guest_push_callback_url',
        'monitoring_guest_push_last_sample_at',
        'monitoring_guest_script_sha',
        'monitoring_guest_script_sha256',
        'monitoring_guest_script_upgraded_at',
        'monitoring_guest_verify_checked_at',
    ];

    /**
     * @return array<string, mixed>
     */
    private function monitoringMeta(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];

        $monitoring = [];
        foreach (self::MONITORING_META_KEYS as $key) {
            if (array_key_exists($key, $meta)) {
                $monitoring[$key] = $meta[$key];
            }
        }

        $monitoring['monitoring_guest_push_token_present'] = ! empty($meta['monitoring_guest_push_token_hash'])
            || ! empty($meta['monitoring_guest_push_cipher']);

        return $monitoring;
    }

    /**
     * @return array<string, float>|null
     */
    private function thresholdsPayload(Server $server): ?array
    {
        $thresholds = data_get($server->meta, 'metric_thresholds');

        return is_array($thresholds) ? array_map(static fn ($v): float => (float) $v, $thresholds) : null;
    }

    /**
     * Same gate as InteractsWithServerWorkspace::serverOpsReady() — this is the
     * control plane that actually holds the key, so it must still hold one.
     */
    private function opsReady(Server $server): bool
    {
        return $server->isReady()
            && $server->isVmHost()
            && filled($server->ip_address)
            && filled($server->ssh_private_key);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('api_organization');
    }

    private function assertServerOrg(Server $server, Organization $organization): void
    {
        abort_if($server->organization_id !== $organization->id, 403);
    }
}
