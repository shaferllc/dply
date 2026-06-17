<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\PollDoksClusterStatusJob;
use App\Jobs\PollEksClusterStatusJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * @phpstan-require-extends Component
 *
 * @property Server|null $server
 */
trait InteractsWithServerWorkspace
{
    use DispatchesToastNotifications;

    public ?Server $server = null;

    protected function bootWorkspace(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;

        if (! $server->isVmHost()) {
            $allowedRoutes = ['servers.show', 'servers.overview', 'servers.sites'];
            $currentRoute = request()->route()?->getName();
            if (is_string($currentRoute) && ! in_array($currentRoute, $allowedRoutes, true)) {
                $this->redirect(route('servers.show', $server), navigate: true);
            }
        }
    }

    /**
     * For K8s hosts still in PENDING/PROVISIONING, kick a status poll when the
     * user lands on a workspace page so a "cluster gone from provider" case is
     * detected instead of leaving the page stuck on a "provisioning" banner
     * forever. The poller itself handles the 404 → mark error transition;
     * this just makes sure something is dispatching it when the operator is
     * actively looking at the page.
     *
     * Runs synchronously so the very next render reflects the result —
     * otherwise on a real (redis) queue the operator would have to reload to
     * see the error banner. The job is a single GET against DO/EKS, bounded
     * by the HTTP client timeout, so blocking the request is acceptable.
     *
     * Guard: skip if we polled within the last :seconds so an open tab
     * sitting on wire:poll doesn't hammer the provider API. Treat a missing
     * last_polled_at as stale.
     */
    protected function kickClusterPollIfStale(int $staleAfterSeconds = 30): void
    {
        $server = $this->server;
        if ($server === null) {
            return;
        }
        if (($server->meta['host_kind'] ?? null) !== Server::HOST_KIND_KUBERNETES) {
            return;
        }

        if (! in_array($server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING], true)) {
            return;
        }

        $kubernetes = is_array($server->meta['kubernetes'] ?? null) ? $server->meta['kubernetes'] : [];
        $lastPolledAt = $kubernetes['last_polled_at'] ?? null;
        if (is_string($lastPolledAt) && $lastPolledAt !== '') {
            try {
                if (Carbon::parse($lastPolledAt)->isAfter(now()->subSeconds($staleAfterSeconds))) {
                    return;
                }
            } catch (Throwable) {
                // unparseable timestamp — fall through and dispatch anyway
            }
        }

        $provider = (string) ($kubernetes['provider'] ?? 'digitalocean');
        try {
            if ($provider === 'aws') {
                PollEksClusterStatusJob::dispatchSync($server);
            } else {
                PollDoksClusterStatusJob::dispatchSync($server);
            }
        } catch (Throwable) {
            // Swallow — we don't want a transient provider hiccup to take
            // down the workspace page. The async poll (already on the queue
            // from the original create flow) will keep trying.
        }

        // The job mutated server.meta on a fresh() copy; reload our reference
        // so the immediate render sees the new status / last_error.
        $this->server = $server->fresh() ?? $server;
    }

    protected function currentUserIsDeployer(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->currentOrganization()?->userIsDeployer($user) ?? false);
    }

    /** True when the server is ready for SSH-based workspace operations (inventory, manage, metrics install, etc.). */
    protected function serverOpsReady(?Server $server = null): bool
    {
        $s = $server ?? $this->server;
        if ($s === null) {
            return false;
        }

        return $s->isReady()
            && $s->isVmHost()
            && filled($s->ip_address)
            && filled($s->ssh_private_key);
    }

    /**
     * @param  array<string, mixed>|null  $server
     */
    #[On('server-state-updated')]
    public function onServerStateUpdated(string $organizationId, string $action, ?string $serverId = null, ?array $server = null): void
    {
        if ($this->server === null) {
            return;
        }

        if ($this->server->organization_id !== $organizationId) {
            return;
        }

        if ($action === 'deleted' && $serverId === $this->server->id) {
            $this->redirect(route('servers.index'), navigate: true);

            return;
        }

        if ($serverId === $this->server->id || ($server['id'] ?? null) === $this->server->id) {
            $this->server->refresh();
        }
    }

    public function cancelScheduledServerRemoval(): void
    {
        if ($this->server === null) {
            return;
        }

        $this->authorize('delete', $this->server);
        $server = $this->server->fresh();
        if ($server->scheduled_deletion_at === null) {
            return;
        }

        $org = $server->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'server.deletion_schedule_cancelled', $server, [
                'scheduled_deletion_at' => $server->scheduled_deletion_at->toIso8601String(),
            ], null);
        }

        $meta = $server->meta ?? [];
        unset($meta['scheduled_deletion_reason']);
        $server->update([
            'scheduled_deletion_at' => null,
            'meta' => $meta,
        ]);
        $this->server = $server->fresh();
        $this->toastSuccess(__('Scheduled removal was cancelled.'));
    }

    /**
     * @return Collection<int, Workspace>
     */
    protected function workspacesForCurrentServerOrg(): Collection
    {
        if ($this->server === null || ! $this->server->organization_id) {
            return collect();
        }

        return Workspace::query()
            ->where('organization_id', $this->server->organization_id)
            ->orderBy('name')
            ->get();
    }
}
