<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesInsightsBanner
{


    protected function isInsightsBusy(): bool
    {
        $meta = $this->server->fresh()->meta ?? [];
        foreach (['run', 'fix', 'revert'] as $kind) {
            if ($this->kindBusy($meta, $kind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function kindBusy(array $meta, string $kind): bool
    {
        $status = (string) data_get($meta, (string) config("insights_workspace.meta_{$kind}_status_key"));
        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        $startedAt = (string) data_get($meta, (string) config("insights_workspace.meta_{$kind}_started_at_key"));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! Carbon::parse($startedAt)->lt(now()->subSeconds(self::STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function rejectIfInsightsBusy(): bool
    {
        if (! $this->isInsightsBusy()) {
            return false;
        }
        $this->toastError(__('An insights operation is already running on this server. Wait for it to finish before starting another.'));

        return true;
    }

    protected function seedRunBannerMeta(string $runId): void
    {
        $this->writeBannerSeed([
            (string) config('insights_workspace.meta_run_run_id_key') => $runId,
            (string) config('insights_workspace.meta_run_status_key') => 'queued',
            (string) config('insights_workspace.meta_run_started_at_key') => now()->toIso8601String(),
            (string) config('insights_workspace.meta_run_finished_at_key') => null,
            (string) config('insights_workspace.meta_run_error_key') => null,
        ]);
    }

    protected function seedFixBannerMeta(string $runId, int $findingId): void
    {
        $this->writeBannerSeed([
            (string) config('insights_workspace.meta_fix_run_id_key') => $runId,
            (string) config('insights_workspace.meta_fix_finding_id_key') => $findingId,
            (string) config('insights_workspace.meta_fix_status_key') => 'queued',
            (string) config('insights_workspace.meta_fix_started_at_key') => now()->toIso8601String(),
            (string) config('insights_workspace.meta_fix_finished_at_key') => null,
            (string) config('insights_workspace.meta_fix_error_key') => null,
        ]);
    }

    protected function seedRevertBannerMeta(string $runId, int $findingId): void
    {
        $this->writeBannerSeed([
            (string) config('insights_workspace.meta_revert_run_id_key') => $runId,
            (string) config('insights_workspace.meta_revert_finding_id_key') => $findingId,
            (string) config('insights_workspace.meta_revert_status_key') => 'queued',
            (string) config('insights_workspace.meta_revert_started_at_key') => now()->toIso8601String(),
            (string) config('insights_workspace.meta_revert_finished_at_key') => null,
            (string) config('insights_workspace.meta_revert_error_key') => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function writeBannerSeed(array $patch): void
    {
        $fresh = $this->server->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ($patch as $k => $v) {
            $meta[$k] = $v;
        }
        $fresh->update(['meta' => $meta]);
        $this->server->refresh();
    }

    public function pollInsightsStatus(): void
    {
        $this->server->refresh();
        $this->reapStaleInsightsBanner();
    }

    /**
     * Surface a worker-gone-away as a normal banner failure so the operator can dismiss
     * and retry. Without this, a worker that died mid-run (timeout, OOM, signal) leaves
     * status='running' on meta forever and the banner appears permanently stuck — the
     * dismiss button is hidden while busy, and the dispatch gate refuses retries until
     * the stale threshold lapses on its own.
     */
    protected function reapStaleInsightsBanner(): void
    {
        $fresh = $this->server->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        $changed = false;
        $threshold = (int) config('insights_workspace.stale_threshold_seconds', 300);

        foreach (['run', 'fix', 'revert'] as $kind) {
            $statusKey = (string) config("insights_workspace.meta_{$kind}_status_key");
            $startedAtKey = (string) config("insights_workspace.meta_{$kind}_started_at_key");
            $finishedAtKey = (string) config("insights_workspace.meta_{$kind}_finished_at_key");
            $errorKey = (string) config("insights_workspace.meta_{$kind}_error_key");

            $status = (string) data_get($meta, $statusKey);
            if (! in_array($status, ['queued', 'running'], true)) {
                continue;
            }

            $startedAt = (string) data_get($meta, $startedAtKey);
            if ($startedAt === '') {
                continue;
            }
            try {
                $started = Carbon::parse($startedAt);
            } catch (\Throwable) {
                continue;
            }
            if ($started->gt(now()->subSeconds($threshold))) {
                continue;
            }

            $meta[$statusKey] = 'failed';
            $meta[$finishedAtKey] = now()->toIso8601String();
            // Leave error blank — the banner already shows a "failed" headline; surfacing
            // operator-facing details about queue worker timeouts isn't useful here.
            $meta[$errorKey] = null;
            $changed = true;
        }

        if ($changed) {
            $fresh->update(['meta' => $meta]);
            $this->server->refresh();
        }
    }

    public function dismissInsightsBanner(string $kind): void
    {
        $this->authorize('view', $this->server);
        if (! in_array($kind, ['run', 'fix', 'revert'], true)) {
            return;
        }

        $statusKey = (string) config("insights_workspace.meta_{$kind}_status_key");
        $status = (string) data_get($this->server->fresh()->meta ?? [], $statusKey);
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $fresh = $this->server->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ([
            "meta_{$kind}_run_id_key",
            "meta_{$kind}_status_key",
            "meta_{$kind}_started_at_key",
            "meta_{$kind}_finished_at_key",
            "meta_{$kind}_error_key",
        ] as $configKey) {
            unset($meta[(string) config("insights_workspace.{$configKey}")]);
        }
        if ($kind === 'fix' || $kind === 'revert') {
            unset($meta[(string) config("insights_workspace.meta_{$kind}_finding_id_key")]);
        }
        $fresh->update(['meta' => $meta]);
        $this->server->refresh();
    }

    /**
     * @return list<string>
     */
    public function getRunOutputLinesProperty(): array
    {
        return $this->readBannerLines('run');
    }

    /**
     * @return list<string>
     */
    public function getFixOutputLinesProperty(): array
    {
        return $this->readBannerLines('fix');
    }

    /**
     * @return list<string>
     */
    public function getRevertOutputLinesProperty(): array
    {
        return $this->readBannerLines('revert');
    }

    /**
     * @return list<string>
     */
    private function readBannerLines(string $kind): array
    {
        $runId = (string) data_get($this->server->meta ?? [], (string) config("insights_workspace.meta_{$kind}_run_id_key"));
        if ($runId === '') {
            return [];
        }
        $prefix = (string) config("insights_workspace.{$kind}_output_cache_key_prefix");
        $payload = Cache::get($prefix.$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }
}
