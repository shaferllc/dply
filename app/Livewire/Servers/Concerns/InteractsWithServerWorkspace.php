<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

trait InteractsWithServerWorkspace
{
    public Server $server;

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    protected function bootWorkspace(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;

        if (! $server->isVmHost()) {
            $allowedRoutes = ['servers.show', 'servers.sites'];
            $currentRoute = request()->route()?->getName();
            if (is_string($currentRoute) && ! in_array($currentRoute, $allowedRoutes, true)) {
                $this->redirect(route('servers.show', $server), navigate: true);
            }
        }
    }

    protected function currentUserIsDeployer(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->currentOrganization()?->userIsDeployer($user) ?? false);
    }

    /** True when the server is ready for SSH-based workspace operations (inventory, manage, metrics install, etc.). */
    protected function serverOpsReady(): bool
    {
        $s = $this->server;

        return $s->isReady()
            && $s->isVmHost()
            && filled($s->ip_address)
            && filled($s->ssh_private_key);
    }

    #[On('server-state-updated')]
    public function onServerStateUpdated(string $organizationId, string $action, ?string $serverId = null, ?array $server = null): void
    {
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
        session()->flash('success', __('Scheduled removal was cancelled.'));
    }

    /**
     * @return Collection<int, Workspace>
     */
    protected function workspacesForCurrentServerOrg(): Collection
    {
        if (! $this->server->organization_id) {
            return collect();
        }

        return Workspace::query()
            ->where('organization_id', $this->server->organization_id)
            ->orderBy('name')
            ->get();
    }
}
