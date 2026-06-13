<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ApplyInsightFixJob;
use App\Jobs\RevertInsightFixJob;
use App\Models\InsightFinding;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesInsightsFixes
{


    public function openApplyFixModal(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $fix = config('insights.insights.'.$finding->insight_key.'.fix');
        $canFix = is_array($fix) && ($fix['handler'] ?? null);
        if (! $canFix) {
            return;
        }

        $this->applyFixFindingId = $finding->id;
        $this->showApplyFixModal = true;
    }

    public function closeApplyFixModal(): void
    {
        $this->showApplyFixModal = false;
        $this->applyFixFindingId = null;
    }

    public function confirmApplyFix(): void
    {
        if ($this->applyFixFindingId === null) {
            return;
        }

        $findingId = $this->applyFixFindingId;
        $this->closeApplyFixModal();
        $this->applyFix($findingId);
    }

    public function applyFix(int $findingId): void
    {
        $this->authorize('update', $this->server);
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereKey($findingId)
            ->first();
        if ($finding === null || ! $finding->isOpen()) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        // Stamp the run-start markers on the finding (mirrors runFix) so the
        // list row swaps the "Apply fix" button for a queued chip immediately,
        // not minutes later when the worker stamps a terminal key. Without
        // this, the row gives no feedback that the click registered.
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_run_started_at'] = now()->toIso8601String();
        $meta['fix_run_started_by'] = $user->id;
        $meta['fix_run_queue'] = config('queue.default');
        unset(
            $meta['fix_applied_at'],
            $meta['fix_applied_by'],
            $meta['fix_failed_at'],
            $meta['fix_failed_by'],
            $meta['fix_failure_reason'],
            $meta['fix_refused_at'],
            $meta['fix_refused_by'],
            $meta['fix_refusal_reason'],
            $meta['fix_output'],
        );
        $finding->forceFill(['meta' => $meta])->save();

        $runId = (string) Str::ulid();
        $this->seedFixBannerMeta($runId, $finding->id);
        ApplyInsightFixJob::dispatch($finding->id, $user->id, $runId);
        $this->toastSuccess(__('Fix queued — watch the banner for live output.'));
        $this->closeFindingDetailIfMatches($finding->id);
    }

    /**
     * Direct "Run fix now" path used by the detail modal — skips the
     * confirm dialog and immediately stamps a tracking timestamp on the
     * finding so the modal can render an in-flight pill ("Queued") until
     * ApplyInsightFixJob writes its terminal meta keys
     * (fix_applied_at | fix_failed_at | fix_refused_at).
     *
     * The modal stays open: while fix_run_started_at is set without a
     * terminal key, the modal polls and shows live progress.
     */
    public function runFix(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereKey($findingId)
            ->first();

        if ($finding === null || ! $finding->isOpen()) {
            return;
        }

        $fix = config('insights.insights.'.$finding->insight_key.'.fix');
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || $handlerClass === '') {
            return;
        }

        // Pre-flight the handler class HERE in the request cycle so the
        // operator sees a useful toast immediately instead of the queue
        // worker writing fix_handler_missing minutes later. This also
        // catches stale-worker scenarios — if the user added a new fix
        // handler but the long-running queue worker hasn't been restarted
        // yet, the request process knows about the class but the worker
        // doesn't. Better to refuse here than fail mid-run.
        if (! class_exists($handlerClass)) {
            $this->toastError(__('Fix handler class :class is not loadable. Check your queue worker has been restarted after deploying the handler.', [
                'class' => $handlerClass,
            ]));

            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        // Stamp the run-start markers and clear any prior terminal keys
        // from a previous attempt so the modal pill flips back to "Queued"
        // for THIS run instead of staying on the old "Failed".
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_run_started_at'] = now()->toIso8601String();
        $meta['fix_run_started_by'] = $user->id;
        $meta['fix_run_queue'] = config('queue.default');
        unset(
            $meta['fix_applied_at'],
            $meta['fix_applied_by'],
            $meta['fix_failed_at'],
            $meta['fix_failed_by'],
            $meta['fix_failure_reason'],
            $meta['fix_refused_at'],
            $meta['fix_refused_by'],
            $meta['fix_refusal_reason'],
            $meta['fix_output'],
        );
        $finding->forceFill(['meta' => $meta])->save();

        $runId = (string) Str::ulid();
        $this->seedFixBannerMeta($runId, $finding->id);

        // ApplyInsightFixJob implements ShouldQueue + Queueable, so this
        // dispatches to the configured queue connection (sync only when
        // QUEUE_CONNECTION=sync — in production it lands in the worker).
        ApplyInsightFixJob::dispatch($finding->id, $user->id, $runId);

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.fix_run_dispatched', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'queue' => config('queue.default'),
            ]);
        }

        $this->toastSuccess(__('Fix queued — tracking progress here.'));
    }

    public function revertFix(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($findingId)
            ->first();
        if ($finding === null) {
            return;
        }

        $backupPath = $finding->meta['backup_path'] ?? null;
        if (! is_string($backupPath) || $backupPath === '') {
            return;
        }

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        $runId = (string) Str::ulid();
        $this->seedRevertBannerMeta($runId, $finding->id);
        RevertInsightFixJob::dispatch($finding->id, $user->id, $runId);
        $this->toastSuccess(__('Revert queued — watch the banner for live output.'));
        $this->closeFindingDetailIfMatches($finding->id);
    }
}
