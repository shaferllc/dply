<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\ApplyEdgeBackendConfigsJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Models\ConsoleAction;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\ServerConsoleActionLookup;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerEdgeProxy
{
    /**
     * Inflight check for edge-proxy add/remove. Same shape as
     * {@see hasInflightWebserverSwitch()} but scoped to the `edge_proxy`
     * console-action kind so the two banners don't shadow each other.
     */
    public function hasInflightEdgeProxyAction(): bool
    {
        return app(ServerConsoleActionLookup::class)->hasInflightEdgeProxy($this->server);
    }

    /**
     * Dispatch {@see AddEdgeProxyJob}. Seeds a queued ConsoleAction so the
     * banner shows immediately rather than blanking until the worker picks
     * the job up.
     */
    public function addEdgeProxy(string $target): void
    {
        $this->authorize('update', $this->server);

        $target = strtolower(trim($target));
        $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
        if (! isset($catalog[$target])) {
            $this->toastError(__('Unknown edge proxy: :t.', ['t' => $target]));

            return;
        }

        if (EdgeProxyWorkspaceViewData::isComingSoonEdgeProxy($target)) {
            $label = $catalog[$target]['label'] ?? $target;
            $this->toastError(__(':engine edge proxy is coming soon.', ['engine' => $label]));

            return;
        }

        if (! in_array($target, EdgeProxyWorkspaceViewData::installableEdgeProxies(), true)) {
            $label = $catalog[$target]['label'] ?? $target;
            $this->toastError(__(':engine edge proxy is coming soon.', ['engine' => $label]));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $currentEdge = $this->server->edgeProxy();
        $isSwitch = $currentEdge !== null && $currentEdge !== $target;
        $targetLabel = $catalog[$target]['label'] ?? $target;

        $this->seedQueuedEdgeProxyAction(
            label: $isSwitch
                ? __('Switching edge proxy to :target …', ['target' => $targetLabel])
                : __('Adding edge proxy: :target …', ['target' => $targetLabel]),
            meta: ['op' => $isSwitch ? 'switch' : 'add', 'target' => $target, 'from' => $currentEdge],
        );

        AddEdgeProxyJob::dispatch(
            serverId: $this->server->id,
            target: $target,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge proxy queued. Progress shows in the banner above.'));
    }

    /**
     * Rebuild every site's Caddy backend + TLS configs and the active edge
     * routing file (Envoy / HAProxy / Traefik). Repair when preview URLs or
     * HTTPS fronts drift after cutover.
     */
    public function applyEdgeBackendConfigs(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run service actions on servers.'));

            return;
        }

        $edge = $this->server->edgeProxy();
        if ($edge === null) {
            $this->toastError(__('No edge proxy is active on this server.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
        $edgeLabel = $catalog[$edge]['label'] ?? ucfirst($edge);

        $this->seedQueuedEdgeProxyAction(
            label: __('Applying webserver config (edge backends + :edge routing)…', ['edge' => $edgeLabel]),
            meta: ['op' => 'apply_backends', 'target' => $edge],
        );

        ApplyEdgeBackendConfigsJob::dispatch(
            serverId: $this->server->id,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge backend sync queued. Progress shows in the banner above.'));
    }

    /**
     * Dispatch {@see RemoveEdgeProxyJob}. Caddy takes over :80 again once
     * the job lands; meta.webserver lands as 'caddy' since that's what's
     * actually serving content post-remove.
     */
    public function removeEdgeProxy(): void
    {
        $this->authorize('update', $this->server);

        $edge = $this->server->edgeProxy();
        if ($edge === null) {
            $this->toastError(__('No edge proxy is active on this server.'));

            return;
        }
        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $this->seedQueuedEdgeProxyAction(
            label: __('Removing edge proxy: :target …', ['target' => $edge]),
            meta: ['op' => 'remove', 'target' => $edge],
        );

        RemoveEdgeProxyJob::dispatch(
            serverId: $this->server->id,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge proxy removal queued. Progress shows in the banner above.'));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function seedQueuedEdgeProxyAction(?string $label, array $meta = []): ConsoleAction
    {
        $subjectType = $this->server->getMorphClass();
        $subjectId = $this->server->id;

        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        $output = [
            'v' => (int) config('console_actions.current_version', 1),
            'lines' => [],
            'meta' => $meta,
        ];

        $action = ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'edge_proxy',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);

        app(ServerConsoleActionLookup::class)->forget($this->server);

        return $action;
    }
}
