<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ConfigureHAProxyLoadBalancerJob;
use App\Jobs\ProvisionHetznerLoadBalancerJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesLoadBalancerNotifications;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerService;
use App\Models\LoadBalancerTarget;
use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

#[Lazy]
class WorkspaceLoadBalancers extends Component
{
    use RendersWorkspacePlaceholder;
    use AuthorizesRequests;
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesLoadBalancerNotifications;

    public Server $server;

    // ── Create form ───────────────────────────────────────────────────────────
    public string $lb_name = '';
    public string $lb_type = 'lb11';
    public string $lb_algorithm = 'round_robin';
    public string $lb_network_id = '';
    /** @var list<string> Server IDs to add as targets */
    public array $lb_target_server_ids = [];
    /** @var list<array{protocol:string,listen_port:string,destination_port:string}> */
    public array $lb_services = [
        ['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80'],
    ];

    // ── HAProxy (software) create form ───────────────────────────────────────
    public string $haproxy_server_id = '';

    // ── Manage: add target ────────────────────────────────────────────────────
    public string $add_target_lb_id = '';
    public string $add_target_server_id = '';

    public function mount(Server $server): void
    {
        $this->server = $server;
        $this->lb_name = $server->name.'-lb';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->lb_workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function addServiceRow(): void
    {
        $this->lb_services[] = ['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80'];
    }

    public function removeServiceRow(int $index): void
    {
        array_splice($this->lb_services, $index, 1);
        $this->lb_services = array_values($this->lb_services);
    }

    public function createLoadBalancer(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'lb_name' => 'required|string|max:255',
            'lb_type' => 'required|in:'.implode(',', LoadBalancer::TYPES),
            'lb_algorithm' => 'required|in:round_robin,least_connections',
            'lb_services' => 'required|array|min:1',
            'lb_services.*.protocol' => 'required|in:http,https,tcp',
            'lb_services.*.listen_port' => 'required|integer|min:1|max:65535',
            'lb_services.*.destination_port' => 'required|integer|min:1|max:65535',
        ]);

        if ($this->server->provider->value !== 'hetzner') {
            $this->toastError(__('Load balancers are currently supported for Hetzner servers only.'));

            return;
        }

        $credential = $this->server->providerCredential;
        if (! $credential) {
            $this->toastError(__('No provider credential found for this server.'));

            return;
        }

        $lb = LoadBalancer::query()->create([
            'organization_id' => $this->server->organization_id,
            'provider_credential_id' => $credential->id,
            'name' => trim($this->lb_name),
            'provider' => 'hetzner',
            'region' => $this->server->region,
            'load_balancer_type' => $this->lb_type,
            'algorithm' => $this->lb_algorithm,
            'status' => LoadBalancer::STATUS_PROVISIONING,
            'hetzner_network_id' => $this->lb_network_id !== '' ? $this->lb_network_id : $this->server->hetzner_network_id,
        ]);

        // Seed targets.
        $targetServerIds = array_filter(array_unique($this->lb_target_server_ids));
        foreach ($targetServerIds as $serverId) {
            $s = Server::query()->where('organization_id', $this->server->organization_id)->find($serverId);
            if ($s) {
                LoadBalancerTarget::query()->create([
                    'load_balancer_id' => $lb->id,
                    'server_id' => $s->id,
                    'provider_server_id' => $s->provider_id,
                ]);
            }
        }

        // Seed services.
        foreach ($this->lb_services as $svc) {
            LoadBalancerService::query()->create([
                'load_balancer_id' => $lb->id,
                'protocol' => $svc['protocol'],
                'listen_port' => (int) $svc['listen_port'],
                'destination_port' => (int) $svc['destination_port'],
                'health_check_port' => (int) $svc['destination_port'],
                'health_check_protocol' => in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp',
            ]);
        }

        ProvisionHetznerLoadBalancerJob::dispatch($lb->id);

        $this->dispatchLoadBalancerNotification('created', [$lb->name], [
            'load_balancer_id' => $lb->id,
            'provider' => 'hetzner',
            'load_balancer_type' => $lb->load_balancer_type,
        ]);

        $this->dispatch('close-modal', 'create-lb-modal');
        $this->lb_name = $this->server->name.'-lb';
        $this->lb_target_server_ids = [];
        $this->lb_services = [['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80']];

        $this->toastSuccess(__('Load balancer ":name" is being provisioned — it will appear here once the IP is assigned (~30 s).', ['name' => $lb->name]));
    }

    /**
     * Create a software (HAProxy) load balancer on a server provisioned with
     * the load_balancer role. No cloud API calls — just SSH config writes.
     */
    public function createHAProxyLoadBalancer(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'lb_name' => 'required|string|max:255',
            'lb_algorithm' => 'required|in:round_robin,least_connections',
            'lb_services' => 'required|array|min:1',
            'lb_services.*.protocol' => 'required|in:http,https,tcp',
            'lb_services.*.listen_port' => 'required|integer|min:1|max:65535',
            'lb_services.*.destination_port' => 'required|integer|min:1|max:65535',
            'haproxy_server_id' => 'required|string',
        ]);

        $haproxyServer = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('status', Server::STATUS_READY)
            ->find($this->haproxy_server_id);

        if (! $haproxyServer) {
            $this->addError('haproxy_server_id', __('Server not found.'));

            return;
        }

        $lb = LoadBalancer::query()->create([
            'organization_id' => $this->server->organization_id,
            'server_id' => $haproxyServer->id,
            'name' => trim($this->lb_name),
            'provider' => LoadBalancer::PROVIDER_HAPROXY,
            'region' => $haproxyServer->region,
            'load_balancer_type' => 'haproxy',
            'algorithm' => $this->lb_algorithm,
            'status' => LoadBalancer::STATUS_PROVISIONING,
        ]);

        foreach (array_filter(array_unique($this->lb_target_server_ids)) as $serverId) {
            $s = Server::query()->where('organization_id', $this->server->organization_id)->find($serverId);
            if ($s) {
                LoadBalancerTarget::query()->create([
                    'load_balancer_id' => $lb->id,
                    'server_id' => $s->id,
                ]);
            }
        }

        foreach ($this->lb_services as $svc) {
            LoadBalancerService::query()->create([
                'load_balancer_id' => $lb->id,
                'protocol' => $svc['protocol'],
                'listen_port' => (int) $svc['listen_port'],
                'destination_port' => (int) $svc['destination_port'],
                'health_check_port' => (int) $svc['destination_port'],
                'health_check_protocol' => in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp',
            ]);
        }

        ConfigureHAProxyLoadBalancerJob::dispatch($lb->id);

        $this->dispatchLoadBalancerNotification('created', [$lb->name], [
            'load_balancer_id' => $lb->id,
            'provider' => 'haproxy',
            'haproxy_server' => $haproxyServer->name,
        ]);

        $this->dispatch('close-modal', 'create-haproxy-lb-modal');
        $this->lb_name = $this->server->name.'-lb';
        $this->lb_target_server_ids = [];
        $this->lb_services = [['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80']];
        $this->haproxy_server_id = '';

        $this->toastSuccess(__('Software load balancer ":name" being configured on :server.', [
            'name' => $lb->name,
            'server' => $haproxyServer->name,
        ]));
    }

    public function addTarget(string $lbId): void
    {
        $this->authorize('update', $this->server);

        $lb = LoadBalancer::query()
            ->where('organization_id', $this->server->organization_id)
            ->find($lbId);

        $target = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->find($this->add_target_server_id);

        if (! $lb || ! $target) {
            $this->toastError(__('Load balancer or server not found.'));

            return;
        }

        $exists = LoadBalancerTarget::query()
            ->where('load_balancer_id', $lb->id)
            ->where('server_id', $target->id)
            ->exists();

        if ($exists) {
            $this->toastError(__(':name is already a target on this load balancer.', ['name' => $target->name]));

            return;
        }

        if ($lb->isSoftware()) {
            // HAProxy — just add to DB and re-apply config.
            LoadBalancerTarget::query()->create([
                'load_balancer_id' => $lb->id,
                'server_id' => $target->id,
            ]);
            ConfigureHAProxyLoadBalancerJob::dispatch($lb->id);
        } else {
            try {
                $hetzner = new HetznerService($lb->providerCredential);
                $hetzner->addLoadBalancerTarget(
                    (int) $lb->provider_id,
                    (int) $target->provider_id,
                    filled($lb->hetzner_network_id),
                );
            } catch (\Throwable $e) {
                $this->toastError(Str::limit($e->getMessage(), 200));

                return;
            }

            LoadBalancerTarget::query()->create([
                'load_balancer_id' => $lb->id,
                'server_id' => $target->id,
                'provider_server_id' => $target->provider_id,
            ]);
        }

        $this->dispatchLoadBalancerNotification('target_added', [$target->name], [
            'load_balancer_id' => $lb->id,
            'load_balancer_name' => $lb->name,
            'target_server_id' => $target->id,
        ]);

        $this->add_target_server_id = '';
        $this->toastSuccess(__(':name added as a target.', ['name' => $target->name]));
    }

    public function removeTarget(string $targetId): void
    {
        $this->authorize('update', $this->server);

        $target = LoadBalancerTarget::query()
            ->whereHas('loadBalancer', fn ($q) => $q->where('organization_id', $this->server->organization_id))
            ->with(['loadBalancer.providerCredential', 'server'])
            ->find($targetId);

        if (! $target) {
            return;
        }

        $removedTargetName = $target->server?->name ?? __('a server');
        $removedLbId = $target->loadBalancer->id;
        $removedLbName = $target->loadBalancer->name;

        if ($target->loadBalancer->isSoftware()) {
            $target->delete();
            ConfigureHAProxyLoadBalancerJob::dispatch($target->loadBalancer->id);
        } else {
            try {
                if ($target->loadBalancer->provider_id && $target->server?->provider_id) {
                    $hetzner = new HetznerService($target->loadBalancer->providerCredential);
                    $hetzner->removeLoadBalancerTarget(
                        (int) $target->loadBalancer->provider_id,
                        (int) $target->server->provider_id,
                    );
                }
            } catch (\Throwable $e) {
                $this->toastError(Str::limit($e->getMessage(), 200));

                return;
            }

            $target->delete();
        }

        $this->dispatchLoadBalancerNotification('target_removed', [$removedTargetName], [
            'load_balancer_id' => $removedLbId,
            'load_balancer_name' => $removedLbName,
        ]);

        $this->toastSuccess(__('Target removed.'));
    }

    public function deleteLoadBalancer(string $lbId): void
    {
        $this->authorize('update', $this->server);

        $lb = LoadBalancer::query()
            ->where('organization_id', $this->server->organization_id)
            ->find($lbId);

        if (! $lb) {
            return;
        }

        $deletedLbName = (string) $lb->name;

        if ($lb->isSoftware()) {
            ConfigureHAProxyLoadBalancerJob::dispatch($lb->id, remove: true);
            $lb->delete();
            $this->dispatchLoadBalancerNotification('deleted', [$deletedLbName], ['provider' => 'haproxy']);
            $this->toastSuccess(__('Load balancer deleted.'));

            return;
        }

        if ($lb->provider_id && $lb->providerCredential) {
            try {
                $hetzner = new HetznerService($lb->providerCredential);
                $hetzner->deleteLoadBalancer((int) $lb->provider_id);
            } catch (\Throwable $e) {
                $this->toastError(Str::limit($e->getMessage(), 200));

                return;
            }
        }

        $lb->delete();
        $this->dispatchLoadBalancerNotification('deleted', [$deletedLbName], ['provider' => 'hetzner']);
        $this->toastSuccess(__('Load balancer deleted.'));
    }

    public function render(): View
    {
        // Filtered read-only view — only LBs that target THIS server.
        $loadBalancers = LoadBalancer::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereHas('targets', fn ($q) => $q->where('server_id', $this->server->id))
            ->orWhere('server_id', $this->server->id) // HAProxy server itself
            ->with(['targets.server', 'services'])
            ->orderBy('created_at', 'desc')
            ->get();

        $orgServers = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('status', Server::STATUS_READY)
            ->whereNotIn('provider', ['digitalocean_functions', 'aws_lambda'])
            ->orderBy('name')
            ->get();

        $needsNotifications = $this->lb_workspace_tab === 'notifications';

        return view('livewire.servers.workspace-load-balancers', [
            'loadBalancers' => $loadBalancers,
            'orgServers' => $orgServers,
            'notifChannels' => $needsNotifications ? $this->assignableLoadBalancerNotificationChannels() : collect(),
            'notifSubscriptions' => $needsNotifications ? $this->loadBalancerNotificationSubscriptions() : collect(),
            'notifEventLabels' => $needsNotifications ? $this->loadBalancerEventLabels() : [],
        ]);
    }
}
