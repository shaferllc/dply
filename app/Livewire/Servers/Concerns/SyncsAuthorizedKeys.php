<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\SyncAuthorizedKeysJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait SyncsAuthorizedKeys
{


    protected function isSyncBusy(): bool
    {
        $meta = $this->server->fresh()->meta ?? [];
        $status = (string) data_get($meta, config('server_ssh_keys.meta_sync_status_key'));

        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        // Treat a long-running queued/running entry as stale so the operator isn't permanently
        // locked out by a worker that quietly died. The next dispatch will overwrite the meta.
        $startedAt = (string) data_get($meta, config('server_ssh_keys.meta_sync_started_at_key'));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! Carbon::parse($startedAt)
                ->lt(now()->subSeconds(self::SYNC_STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            // Unparseable started_at → fail open and let the operator retry.
            return false;
        }
    }

    protected function rejectIfSyncBusy(): bool
    {
        if (! $this->isSyncBusy()) {
            return false;
        }

        $this->toastError(__('A sync is already in flight on this server. Wait for it to finish before starting another action.'));

        return true;
    }

    public function requestSyncAuthorizedKeys(): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $keys = $this->server->fresh(['authorizedKeys'])->authorizedKeys;

        if ($keys->isEmpty()) {
            $this->openConfirmActionModal(
                'syncAuthorizedKeys',
                [],
                __('Sync with no keys?'),
                __('Dply will write an empty authorized_keys to the server. Anyone currently using SSH (including Dply itself) will be locked out until you add a key out-of-band. This cannot be undone from the workspace.'),
                __('Sync anyway'),
                true,
            );

            return;
        }

        $loginUser = (string) $this->server->ssh_user;
        // An empty `target_linux_user` on a row means "default to the server's login user", so
        // those rows count toward the login user's keys when checking lockout risk.
        $hasKeyForLoginUser = $keys->contains(static function ($k) use ($loginUser): bool {
            $target = (string) ($k->target_linux_user ?? '');

            return $target === '' || $target === $loginUser;
        });

        if (! $hasKeyForLoginUser && $loginUser !== '') {
            $this->openConfirmActionModal(
                'syncAuthorizedKeys',
                [],
                __('Sync without a key for the login user?'),
                __('No key in this set targets :user — the user Dply uses to SSH into this server. Syncing now will lock Dply out until you restore the key out-of-band. Other system users may still keep their keys.', ['user' => $loginUser]),
                __('Sync anyway'),
                true,
            );

            return;
        }

        $this->syncAuthorizedKeys();
    }

    /**
     * Dispatch a queued sync. The actual SSH work happens inside {@see SyncAuthorizedKeysJob};
     * the workspace banner polls server.meta for status and reads the streaming output buffer
     * out of the application cache. Idempotent — if a sync is already running on this server,
     * we no-op and let the banner keep tracking the in-flight run.
     */
    public function syncAuthorizedKeys(): void
    {
        $this->authorize('update', $this->server);

        // Defense-in-depth — `requestSyncAuthorizedKeys` already gates on isSyncBusy(); this
        // catches direct invocations (e.g. the confirm-modal callback) and uses the same
        // staleness threshold so a stuck queued/running entry never permanently locks dispatch.
        if ($this->isSyncBusy()) {
            $this->toastError(__('A sync is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_sync_run_id_key')] = $runId;
        $meta[config('server_ssh_keys.meta_sync_status_key')] = 'queued';
        $meta[config('server_ssh_keys.meta_sync_started_at_key')] = now()->toIso8601String();
        $meta[config('server_ssh_keys.meta_sync_finished_at_key')] = null;
        $meta[config('server_ssh_keys.meta_sync_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        SyncAuthorizedKeysJob::dispatch(
            $this->server->id,
            $runId,
            Auth::id(),
            Request::ip(),
        );

        $this->toastSuccess(__('Sync queued — watch the banner for live output. You can leave this page; the job runs on the queue.'));
    }

    /**
     * Banner re-render tick — handles both sync and drift runs. Refreshes the server row so the
     * latest meta + queue-driven status lands on the page. On sync completion, reloads the
     * per-key review-date inputs (synced_at may have changed). On drift completion, hydrates
     * `$diff_output` and `$diff_result` from the cache payload the job wrote. Called via
     * wire:poll while either run is active.
     */
    public function pollSyncStatus(): void
    {
        $this->server->refresh();

        $syncStatus = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
        if ($syncStatus === 'completed' || $syncStatus === 'failed') {
            $this->loadReviewDateInputs();
        }

        // A successful sync rewrote authorized_keys to match the desired set. Anything the user
        // was looking at in the Drift tab is now stale and would mislead ("but I just synced — why
        // is there still drift?"). Drop the structured diff and the transcript on transition to
        // completed; user can click Refresh preview to confirm the post-sync state.
        if ($syncStatus === 'completed' && $this->diff_result !== null) {
            $this->diff_result = null;
            $this->diff_output = [];

            $meta = $this->server->meta ?? [];
            unset(
                $meta[config('server_ssh_keys.meta_drift_run_id_key')],
                $meta[config('server_ssh_keys.meta_drift_status_key')],
                $meta[config('server_ssh_keys.meta_drift_started_at_key')],
                $meta[config('server_ssh_keys.meta_drift_finished_at_key')],
                $meta[config('server_ssh_keys.meta_drift_error_key')],
            );
            $this->server->fresh()->update(['meta' => $meta]);
            $this->server->refresh();
        }

        $driftStatus = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
        $driftRunId = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_drift_run_id_key'));
        if ($driftRunId !== '') {
            $cacheKey = (string) config('server_ssh_keys.drift_output_cache_key_prefix', 'ssh_key_drift_output:').$driftRunId;
            $payload = Cache::get($cacheKey);
            if (is_array($payload)) {
                $lines = $payload['lines'] ?? [];
                $this->diff_output = is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
                if ($driftStatus === 'completed' && isset($payload['diff_result']) && is_array($payload['diff_result'])) {
                    $this->diff_result = $payload['diff_result'];
                }
            }
        }
    }

    /**
     * Operator-initiated dismissal of a finished run banner. Clears the meta keys so the
     * banner stops rendering on subsequent polls. No-op while a run is still in flight —
     * dismissing mid-sync would just hide a banner the operator probably wants to see.
     */
    /**
     * Clear the drift-preview banner. Resets the run meta keys (so subsequent renders skip
     * the banner) and the local transcript. Preserves `$diff_result` because operators usually
     * want the structured diff to stay visible on the Drift tab even after dismissing the
     * banner. No-op while a run is still in flight — same rule as dismissSyncBanner.
     */
    public function dismissDriftBanner(): void
    {
        $this->authorize('view', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_ssh_keys.meta_drift_run_id_key')],
            $meta[config('server_ssh_keys.meta_drift_status_key')],
            $meta[config('server_ssh_keys.meta_drift_started_at_key')],
            $meta[config('server_ssh_keys.meta_drift_finished_at_key')],
            $meta[config('server_ssh_keys.meta_drift_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $this->diff_output = [];
    }

    public function dismissSyncBanner(): void
    {
        $this->authorize('update', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_ssh_keys.meta_sync_run_id_key')],
            $meta[config('server_ssh_keys.meta_sync_status_key')],
            $meta[config('server_ssh_keys.meta_sync_started_at_key')],
            $meta[config('server_ssh_keys.meta_sync_finished_at_key')],
            $meta[config('server_ssh_keys.meta_sync_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
    }

    /**
     * Streaming output buffer for the active (or most recent) sync run. Reads the cache
     * payload written by {@see SyncAuthorizedKeysJob} — empty list when no run has happened
     * recently, when the cache TTL has lapsed, or when the run hasn't emitted anything yet.
     *
     * @return list<string>
     */
    public function getSyncOutputLinesProperty(): array
    {
        $runId = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_sync_run_id_key'));
        if ($runId === '') {
            return [];
        }
        $payload = Cache::get((string) config('server_ssh_keys.sync_output_cache_key_prefix', 'ssh_key_sync_output:').$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    protected function friendlyWorkspaceError(\Throwable $e, string $defaultMessage): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return $defaultMessage;
        }

        if (str_contains($message, 'Permission denied (publickey)')) {
            return $defaultMessage.' '.__('The server rejected the SSH key for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Could not create script directory')) {
            return $defaultMessage.' '.__('The server did not allow Dply to start a remote SSH task for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Failed to execute task:')) {
            return $defaultMessage;
        }

        return $message;
    }
}
