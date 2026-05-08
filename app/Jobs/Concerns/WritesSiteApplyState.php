<?php

namespace App\Jobs\Concerns;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shared meta-state writer for site-server jobs. Anything that interacts with the
 * remote host (apply webserver config, issue SSL, mutate system user, write
 * systemd units, reset permissions, …) uses this trait so a single banner can
 * track running/completed/failed regardless of which job is actually executing.
 *
 * Implementing class is expected to declare its kind via {@see applyKind()}.
 *
 * Meta keys are read from config('site_webserver_config.*'); the kind slug is
 * what the banner getter uses to pick user-facing copy from
 * config('site_webserver_config.apply_kinds').
 */
trait WritesSiteApplyState
{
    /**
     * Slug for this job's kind. Must match a key in
     * config('site_webserver_config.apply_kinds'). Override per job.
     */
    abstract protected function applyKind(): string;

    /**
     * Seed a "queued" apply state for a site BEFORE the job is dispatched, so the
     * banner shows up immediately on the next render instead of waiting for the
     * worker to pick up the job and write status=running.
     *
     * Called from the dispatch site (controller/Livewire), not from inside the
     * worker. The worker's beginApplyRun() will overwrite these values with its
     * own run_id/started_at when it actually starts.
     *
     * Example:
     *   WritesSiteApplyState::seedQueuedRun($site, kind: 'webserver_config');
     *   ApplySiteWebserverConfigJob::dispatch($site->id);
     */
    public static function seedQueuedRun(Site $site, string $kind): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta[config('site_webserver_config.meta_apply_run_id_key')] = (string) Str::ulid();
        $meta[config('site_webserver_config.meta_apply_status_key')] = 'running';
        $meta[config('site_webserver_config.meta_apply_kind_key')] = $kind;
        $meta[config('site_webserver_config.meta_apply_started_at_key')] = now()->toIso8601String();
        unset(
            $meta[config('site_webserver_config.meta_apply_finished_at_key')],
            $meta[config('site_webserver_config.meta_apply_error_key')],
        );
        $site->update(['meta' => $meta]);
    }

    /**
     * Mark the run as started. Writes a fresh run_id, status=running, and the
     * job's kind slug. Returns the run_id so the caller can later cache output
     * keyed by it.
     */
    protected function beginApplyRun(Site $site): string
    {
        $runId = (string) Str::ulid();

        $this->writeApplyState($site, [
            config('site_webserver_config.meta_apply_run_id_key') => $runId,
            config('site_webserver_config.meta_apply_status_key') => 'running',
            config('site_webserver_config.meta_apply_kind_key') => $this->applyKind(),
            config('site_webserver_config.meta_apply_started_at_key') => now()->toIso8601String(),
            config('site_webserver_config.meta_apply_finished_at_key') => null,
            config('site_webserver_config.meta_apply_error_key') => null,
        ]);

        return $runId;
    }

    /**
     * Mark the run as completed.
     */
    protected function completeApplyRun(Site $site): void
    {
        $this->writeApplyState($site, [
            config('site_webserver_config.meta_apply_status_key') => 'completed',
            config('site_webserver_config.meta_apply_finished_at_key') => now()->toIso8601String(),
            config('site_webserver_config.meta_apply_error_key') => null,
        ]);
    }

    /**
     * Mark the run as failed and record the error message. Optional
     * statusOverride flips site.status (e.g. to STATUS_ERROR) in the same write.
     */
    protected function failApplyRun(Site $site, string $error, ?string $statusOverride = null, array $extraMeta = []): void
    {
        $patch = array_merge($extraMeta, [
            config('site_webserver_config.meta_apply_status_key') => 'failed',
            config('site_webserver_config.meta_apply_finished_at_key') => now()->toIso8601String(),
            config('site_webserver_config.meta_apply_error_key') => $error,
        ]);

        $this->writeApplyState($site, $patch, statusOverride: $statusOverride);
    }

    /**
     * Cache the (potentially long) command transcript for this run. Bounded
     * by config so apply_output payloads stay small.
     */
    protected function cacheApplyOutput(string $runId, string $output): void
    {
        $maxLines = (int) config('site_webserver_config.apply_output_max_lines', 400);
        $lines = preg_split('/\r?\n/', rtrim($output, "\n")) ?: [];
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        Cache::put(
            config('site_webserver_config.apply_output_cache_key_prefix').$runId,
            ['lines' => array_values($lines)],
            (int) config('site_webserver_config.apply_output_cache_ttl_seconds', 300),
        );
    }

    /**
     * Merge the given values into the site's meta JSON, preserving keys we don't
     * touch. Null values delete the corresponding meta key.
     */
    private function writeApplyState(Site $site, array $patch, ?string $statusOverride = null): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($meta[$k]);

                continue;
            }
            $meta[$k] = $v;
        }

        $update = ['meta' => $meta];
        if ($statusOverride !== null) {
            $update['status'] = $statusOverride;
        }
        $site->update($update);
    }
}
