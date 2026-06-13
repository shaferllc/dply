<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Jobs\SyncWorkerPoolEnvJob;
use App\Models\ConsoleAction;
use App\Models\Site;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteEnvSync
{


    public function updatedEnvSearch(): void
    {
        $this->env_page = 1;
    }

    public function updatedEnvGroup(): void
    {
        $this->env_page = 1;
    }

    /**
     * Manual push of the cache to the server's .env (console banner).
     */
    public function pushEnvToServer(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not support pushing a .env file over SSH.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_push');

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment file pushed to server.'), __('Environment push did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * True when this site has worker-pool replicas cloned from it. Drives the
     * "Sync to workers" action — those replicas are env copies that drift from
     * the primary once its variables are edited. {@see SyncWorkerPoolEnvJob}.
     */
    public function hasWorkerReplicas(): bool
    {
        return Site::query()
            ->where('meta->replicated_from_site_id', (string) $this->site->id)
            ->exists();
    }

    /**
     * Opt-in: project the primary site's variables onto every worker-pool
     * replica (preserving each replica's queue, HORIZON, and worker-role keys),
     * then push + restart only the replicas that actually changed.
     */
    public function applyEnvToWorkers(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->hasWorkerReplicas()) {
            $this->toastError(__('This site has no worker replicas to sync.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_push_workers');

        SyncWorkerPoolEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment synced to worker replicas.'), __('Worker env sync did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * Lazy first-visit sync (wire:init): fires the env-sync job only when the
     * cache has never been touched. Read uses 'view' priv.
     */
    public function autoSyncIfFirstVisit(): void
    {
        $this->authorize('view', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            return;
        }
        if (filled($this->site->env_file_content) || $this->site->env_cache_origin !== null) {
            return;
        }

        $inFlight = ConsoleAction::query()
            ->forSubject($this->site)
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->exists();
        if ($inFlight) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('env_sync');
        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );
        $this->watchConsoleAction($run, __('Environment synced from server.'), __('Environment sync did not finish.'));
    }

    /**
     * Manual "re-read the live .env from the server and replace the cache".
     */
    public function syncEnvFromServer(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not expose a server .env file.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_sync');

        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Environment synced from server.'), __('Environment sync did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * One-click "move .env outside docroot" → /etc/dply/<slug>.env + push.
     */
    public function relocateEnvOutsideDocroot(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not have a server .env to relocate.'));

            return;
        }

        $newPath = '/etc/dply/'.$this->site->slug.'.env';
        $this->site->forceFill(['env_file_path' => $newPath])->save();
        $this->env_file_path_override = $newPath;

        $run = $this->seedQueuedConsoleAction('env_push');
        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Relocated .env to :path.', ['path' => $newPath]),
            __('Relocating .env to :path did not finish.', ['path' => $newPath]),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Save a custom absolute .env path on the Site row (empty = default).
     */
    public function saveEnvFilePath(): void
    {
        $this->authorize('update', $this->site);
        if ($this->blockedForDerivedWorker()) {
            return;
        }
        $value = trim($this->env_file_path_override);

        if ($value === '') {
            $this->site->forceFill(['env_file_path' => null])->save();
            $this->autoPushAfterCacheMutation(__('Default .env path restored.'));

            return;
        }

        $this->validate([
            'env_file_path_override' => ['required', 'string', 'max:1024', 'regex:/^\/[^\\\\\\0]+$/'],
        ], [
            'env_file_path_override.regex' => __('Path must be absolute (start with /) and not contain backslashes or null bytes.'),
        ]);

        $this->site->forceFill(['env_file_path' => $value])->save();
        $this->autoPushAfterCacheMutation(__('Custom .env path saved.'));
    }

    /**
     * Single-row add: writes one key into the encrypted env cache, then
     * auto-pushes to the server's .env file.
     */
    /**
     * A derived worker has no environment of its own — it inherits the parent
     * app's, overriding only a handful of role-specific keys. Block edits here
     * and point the operator at the parent. Returns true when blocked so the
     * caller can early-return.
     */
    protected function blockedForDerivedWorker(): bool
    {
        if ($this->site->isDerivedWorker()) {
            $this->toastError(__('This is a worker — its environment is inherited from its parent app. Manage it on the parent app.'));

            return true;
        }

        return false;
    }
}
