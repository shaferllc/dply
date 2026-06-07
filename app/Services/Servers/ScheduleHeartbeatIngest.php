<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\SchedulerTickOutput;
use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ingests `scheduler_heartbeats` entries from the metrics-agent push payload
 * into `server_scheduler_heartbeats`. One row per (server, site, kind);
 * upserts on each push.
 *
 * Resilient by design — the metrics ingest path must not fail because a single
 * heartbeat entry is malformed. Each entry is validated independently; bad
 * entries are logged and skipped, good entries land regardless.
 *
 * Wraps the staleness math (Q4) by checking whether the agent reported a
 * fresher `last_tick_at` than what's stored; on a fresh tick, `consecutive_misses`
 * resets to 0. Otherwise the runner increments it on its own evaluation pass.
 */
class ScheduleHeartbeatIngest
{
    /** Wrapper payload versions we understand. v2 adds stdout_excerpt + capture_enabled. */
    private const SUPPORTED_VERSIONS = [1, 2];

    /**
     * @param  array<int, mixed>  $heartbeats  Raw `scheduler_heartbeats` array from the push payload.
     */
    public function ingest(Server $server, array $heartbeats): void
    {
        if ($heartbeats === []) {
            return;
        }

        foreach ($heartbeats as $index => $raw) {
            try {
                $this->ingestOne($server, $raw);
            } catch (\Throwable $e) {
                Log::warning('scheduler_heartbeat.ingest_failed', [
                    'server_id' => $server->id,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  mixed  $raw
     */
    private function ingestOne(Server $server, $raw): void
    {
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('heartbeat entry is not an object');
        }

        $version = (int) ($raw['v'] ?? 1);
        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            // Future-version sidecars from a newer wrapper than this dply
            // understands — log so we know to ship parser updates, then skip.
            Log::info('scheduler_heartbeat.unsupported_version', [
                'server_id' => $server->id,
                'reported_version' => $version,
            ]);

            return;
        }

        $siteId = $this->normalizeSiteId($raw['site_id'] ?? null);
        if ($siteId === null) {
            throw new \InvalidArgumentException('heartbeat entry missing site_id');
        }

        $site = Site::query()
            ->where('server_id', $server->id)
            ->whereKey($siteId)
            ->first();
        if ($site === null) {
            // Defensive: the agent reported a site that's not in our DB.
            // Could be a stale heartbeat for a deleted site, or wrong server.
            // Don't error — just skip.
            Log::info('scheduler_heartbeat.unknown_site', [
                'server_id' => $server->id,
                'site_id' => $siteId,
            ]);

            return;
        }

        $kind = (string) ($raw['scheduler_kind'] ?? '');
        if (! in_array($kind, ServerSchedulerHeartbeat::kinds(), true)) {
            throw new \InvalidArgumentException("invalid scheduler_kind: {$kind}");
        }

        $cronExpression = trim((string) ($raw['cron_expression'] ?? ''));
        if ($cronExpression === '' || mb_strlen($cronExpression) > 128) {
            throw new \InvalidArgumentException('cron_expression missing or too long');
        }

        $lastTickAt = $this->parseTimestamp($raw['last_tick_at'] ?? $raw['finished_at'] ?? null);
        $exitCode = $this->intOrNull($raw['last_exit_code'] ?? $raw['exit_code'] ?? null);

        // output_capture_enabled is per-scheduler operator state, controlled by
        // the capture toggle (which also writes the on-box control file). The
        // ingest path must NOT clobber it — only set it on create (opt-in off).
        $payload = [
            'cron_expression' => $cronExpression,
            'last_tick_at' => $lastTickAt,
            'last_exit_code' => $exitCode,
            'last_duration_ms' => $this->intOrNull($raw['last_duration_ms'] ?? $raw['duration_ms'] ?? null),
            'last_memory_peak_kb' => $this->intOrNull($raw['last_memory_peak_kb'] ?? $raw['memory_peak_kb'] ?? null),
            'circuit_open' => (bool) ($raw['circuit_open'] ?? false),
        ];

        // Upsert: keep existing row's first_seen_at + consecutive_misses if we
        // already have one. Reset consecutive_misses to 0 only when the
        // reported tick is fresher than what we'd previously stored.
        $existing = ServerSchedulerHeartbeat::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->where('scheduler_kind', $kind)
            ->first();

        if ($existing === null) {
            $heartbeat = ServerSchedulerHeartbeat::query()->create(array_merge($payload, [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'scheduler_kind' => $kind,
                'consecutive_misses' => 0,
                'first_seen_at' => now(),
                'output_capture_enabled' => false, // opt-in; the toggle turns it on.
            ]));

            $this->recordTickOutputIfAny($heartbeat, $raw, $exitCode, $lastTickAt);

            return;
        }

        $previousTickAt = $existing->last_tick_at;
        $tickIsFresh = $lastTickAt !== null
            && ($previousTickAt === null || $lastTickAt->greaterThan($previousTickAt));

        if ($tickIsFresh) {
            $payload['consecutive_misses'] = 0;
        }
        // If the tick isn't fresh, leave consecutive_misses alone — the
        // Insights runner increments it on its own evaluation pass when it
        // detects a missed scheduled-fire window. The ingest endpoint stays
        // out of that math to keep the two paths independently testable.

        $existing->fill($payload)->save();

        // Record output only once per actual tick (the agent re-pushes the same
        // sidecar until a new tick overwrites it).
        if ($tickIsFresh) {
            $this->recordTickOutputIfAny($existing, $raw, $exitCode, $lastTickAt);
        }
    }

    /**
     * Persist a scheduler_tick_outputs row when the sidecar carries output to
     * keep: failures are always recorded; successful-run output only when the
     * wrapper captured it (per-scheduler opt-in). Excerpt fields absent + a
     * zero exit code = nothing worth a row.
     *
     * @param  array<string, mixed>  $raw
     */
    private function recordTickOutputIfAny(
        ServerSchedulerHeartbeat $heartbeat,
        array $raw,
        ?int $exitCode,
        ?\Carbon\Carbon $ranAt,
    ): void {
        $stderr = $this->stringOrNull($raw['stderr_excerpt'] ?? null);
        $stdout = $this->stringOrNull($raw['stdout_excerpt'] ?? null);
        $failed = $exitCode !== null && $exitCode !== 0;

        if (! $failed && $stderr === null && $stdout === null) {
            return; // clean run, capture off — nothing to store.
        }

        SchedulerTickOutput::record(
            heartbeatId: $heartbeat->id,
            trigger: SchedulerTickOutput::TRIGGER_CRON,
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            ranAt: $ranAt,
            durationMs: $this->intOrNull($raw['last_duration_ms'] ?? $raw['duration_ms'] ?? null),
        );
    }

    private function stringOrNull(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    private function normalizeSiteId(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^[A-Za-z0-9]{1,64}$/', $raw)) {
            return null;
        }

        return $raw;
    }

    private function parseTimestamp(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return null;
    }
}
