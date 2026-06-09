<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\PollDoksClusterStatusJob;
use App\Jobs\PollEksClusterStatusJob;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\BuildsContainerLaunchSummary;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;
use Throwable;

/**
 * Single landing page for a Kubernetes-host server through its whole lifecycle:
 *   PROVISIONING → milestone strip ("Created" → "Nodes X/Y" → "Ready") + node-pool table.
 *   READY        → cluster info card + node-pool table + kubeconfig panel + sites list.
 *   ERROR        → red error card + Retry polling + Open in DO console + node-pool table for diagnostics.
 *
 * Data is read from server.meta.kubernetes.snapshot which the
 * {@see PollDoksClusterStatusJob} keeps fresh. The page wire:polls so it
 * reflects the latest job-written state without the user reloading.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCluster extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cluster';

    use BuildsContainerLaunchSummary;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Tracks the "type the cluster name to confirm" gate for destructive delete. */
    public string $deleteConfirmName = '';

    public bool $showDeleteClusterModal = false;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->kickClusterPollIfStale();
    }

    /**
     * Force a fresh poll — surfaced as both the "Retry polling" button on the
     * error card and the "Refresh" button on the READY state. Dispatches the
     * provider-appropriate poller; the job's existing terminal-state logic
     * handles whatever it finds (running stays running, errored flips back to
     * provisioning if the provider recovered, etc.).
     */
    public function retryPolling(): void
    {
        $this->authorize('update', $this->server);
        $this->dispatchClusterPoller();
    }

    /**
     * Same as retryPolling but flashes a success toast — used on the READY
     * state where the user manually wants to re-sync after changing something
     * in the provider console (upgrading the cluster, scaling a nodegroup, etc.).
     */
    public function refreshClusterStatus(): void
    {
        $this->authorize('update', $this->server);
        $this->dispatchClusterPoller();
        $providerLabel = ($this->server->meta['kubernetes']['provider'] ?? '') === 'aws'
            ? 'AWS'
            : 'DigitalOcean';
        session()->flash('success', __('Refreshing cluster status from :provider…', ['provider' => $providerLabel]));
    }

    /**
     * Dispatch the right poller for this server's provider. Centralised so the
     * "which job?" branch lives in one place — Retry and Refresh share it.
     */
    private function dispatchClusterPoller(): void
    {
        $provider = (string) ($this->server->meta['kubernetes']['provider'] ?? 'digitalocean');
        if ($provider === 'aws') {
            PollEksClusterStatusJob::dispatch($this->server);

            return;
        }
        PollDoksClusterStatusJob::dispatch($this->server);
    }

    public function openDeleteClusterModal(): void
    {
        $this->authorize('update', $this->server);
        $this->deleteConfirmName = '';
        $this->showDeleteClusterModal = true;
    }

    public function closeDeleteClusterModal(): void
    {
        $this->showDeleteClusterModal = false;
    }

    /**
     * Adaptive delete:
     *   - provisioned_by_dply: call DO's delete endpoint AND remove the dply row.
     *     Gated on the user typing the cluster name verbatim (matches DO's own UX).
     *   - registered (existing): only remove the dply row — the actual DOKS
     *     cluster stays running in their account, since dply didn't create it.
     */
    public function deleteCluster(): mixed
    {
        $this->authorize('delete', $this->server);
        $kubernetes = is_array($this->server->meta['kubernetes'] ?? null) ? $this->server->meta['kubernetes'] : [];
        $provisionedByDply = (bool) ($kubernetes['provisioned_by_dply'] ?? false);
        $clusterName = (string) ($kubernetes['cluster_name'] ?? '');
        $clusterId = (string) ($kubernetes['cluster_id'] ?? '');

        if ($provisionedByDply) {
            if (trim($this->deleteConfirmName) !== $clusterName) {
                $this->addError('deleteConfirmName', __('Type the cluster name exactly to confirm.'));

                return null;
            }

            $credential = $this->server->providerCredential;
            if ($credential === null || $clusterId === '') {
                $this->addError('deleteConfirmName', __('Cannot delete: missing credential or cluster id. Remove from DigitalOcean console first.'));

                return null;
            }

            try {
                (new DigitalOceanService($credential))->deleteKubernetesCluster($clusterId);
            } catch (Throwable $e) {
                $this->addError('deleteConfirmName', __('DigitalOcean refused to delete the cluster: :detail', ['detail' => $e->getMessage()]));

                return null;
            }
        }

        // Both paths end with removing the dply row. The shared HandlesServerRemovalFlow
        // trait's confirmRemoveServer would also fire deletion jobs we don't need for
        // K8s (no VM teardown work) — so do a direct delete + audit here.
        $org = $this->server->organization;
        $user = auth()->user();
        $this->server->delete();
        if ($org && $user) {
            audit_log($org, $user, 'server.deleted', null, ['name' => $this->server->name]);
        }

        return $this->redirectRoute('servers.index', navigate: true);
    }

    public function render(): View
    {
        $this->server->refresh();
        $kubernetes = is_array($this->server->meta['kubernetes'] ?? null) ? $this->server->meta['kubernetes'] : [];
        $snapshot = is_array($kubernetes['snapshot'] ?? null) ? $kubernetes['snapshot'] : [];
        $status = $this->server->status;
        $hasKubeconfig = isset($kubernetes['kubeconfig']) && trim((string) $kubernetes['kubeconfig']) !== '';

        // Phase: 'provisioning' | 'ready' | 'error'. Drives which top-section
        // partial the blade renders. Same node-pool table sits below all three.
        $phase = match (true) {
            $status === Server::STATUS_ERROR => 'error',
            $status === Server::STATUS_READY => 'ready',
            default => 'provisioning',
        };

        $nodePools = is_array($snapshot['node_pools'] ?? null) ? $snapshot['node_pools'] : [];
        $totalNodes = 0;
        $readyNodes = 0;
        foreach ($nodePools as $pool) {
            if (! is_array($pool)) {
                continue;
            }
            $nodes = is_array($pool['nodes'] ?? null) ? $pool['nodes'] : [];
            foreach ($nodes as $node) {
                $totalNodes++;
                if (is_array($node) && ($node['status']['state'] ?? null) === 'running') {
                    $readyNodes++;
                }
            }
            // Older DOKS responses sometimes omit the per-node list and only
            // give the count — fall back so the X/Y math still works.
            if ($nodes === [] && isset($pool['count'])) {
                $totalNodes += (int) $pool['count'];
            }
        }

        $sites = $this->server->sites()
            ->select(['id', 'name', 'status', 'server_id'])
            ->orderBy('name')
            ->get();

        $containerLaunch = $this->containerLaunchSummary();

        return view('livewire.servers.workspace-cluster', [
            'phase' => $phase,
            'kubernetes' => $kubernetes,
            'snapshot' => $snapshot,
            'nodePools' => $nodePools,
            'totalNodes' => $totalNodes,
            'readyNodes' => $readyNodes,
            'hasKubeconfig' => $hasKubeconfig,
            'sites' => $sites,
            'provisionedByDply' => (bool) ($kubernetes['provisioned_by_dply'] ?? false),
            'containerLaunch' => $containerLaunch,
            'containerLaunchTranscript' => $this->containerLaunchTranscript($containerLaunch),
        ]);
    }
}
