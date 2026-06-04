<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\AttachServerToNetworkJob;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerService;
use App\Models\LoadBalancerTarget;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]

/**
 * Org-level networking workspace: load balancers, private networks, routes.
 *
 * URL: /networking?tab=load-balancers
 *
 * This is the management surface. The per-server Networking tab stays as a
 * filtered read-only view ("which LBs point at me, what network am I on").
 */
class OrgNetworking extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'tab', except: 'load-balancers', history: true)]
    public string $tab = 'load-balancers';

    // ── Load balancer create form (shared with per-server) ────────────────────
    public string $lb_name        = '';
    public string $lb_type        = 'lb11';
    public string $lb_algorithm   = 'round_robin';
    public string $lb_network_id  = '';
    public string $haproxy_server_id = '';
    public array  $lb_target_server_ids = [];
    public array  $lb_services = [
        ['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80'],
    ];

    // ── Network create form ────────────────────────────────────────────────────
    public string $net_name       = '';
    public string $net_ip_range   = '10.0.0.0/8';
    public string $net_credential_id = '';
    public array  $net_server_ids = [];

    // ── Route form (keyed by network ID) ─────────────────────────────────────
    public array $route_destination     = [];
    public array $route_gateway_server  = [];

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['load-balancers', 'networks']) ? $tab : 'load-balancers';
    }

    public function addLbServiceRow(): void
    {
        $this->lb_services[] = ['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80'];
    }

    public function removeLbServiceRow(int $index): void
    {
        array_splice($this->lb_services, $index, 1);
        $this->lb_services = array_values($this->lb_services);
    }

    /** @return array<string,mixed> */
    private function lbServiceRules(): array
    {
        return [
            'lb_name'                        => 'required|string|max:255',
            'lb_algorithm'                   => 'required|in:round_robin,least_connections',
            'lb_services'                    => 'required|array|min:1',
            'lb_services.*.protocol'         => 'required|in:http,https,tcp',
            'lb_services.*.listen_port'      => 'required|integer|min:1|max:65535',
            'lb_services.*.destination_port' => 'required|integer|min:1|max:65535',
        ];
    }

    private function seedLbServices(LoadBalancer $lb): void
    {
        foreach ($this->lb_services as $svc) {
            LoadBalancerService::query()->create([
                'load_balancer_id'      => $lb->id,
                'protocol'              => $svc['protocol'],
                'listen_port'           => (int) $svc['listen_port'],
                'destination_port'      => (int) $svc['destination_port'],
                'health_check_port'     => (int) $svc['destination_port'],
                'health_check_protocol' => in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp',
            ]);
        }
    }

    private function resetLbForm(): void
    {
        $this->lb_name              = '';
        $this->lb_network_id        = '';
        $this->haproxy_server_id    = '';
        $this->lb_target_server_ids = [];
        $this->lb_services          = [['protocol' => 'http', 'listen_port' => '80', 'destination_port' => '80']];
    }

    /**
     * Create a software (HAProxy) load balancer on a server the org already owns.
     */
    public function createHAProxyLoadBalancer(): void
    {
        $this->validate($this->lbServiceRules() + [
            'haproxy_server_id' => 'required|string',
        ]);

        $org = Auth::user()->currentOrganization();

        $haproxyServer = Server::query()
            ->where('organization_id', $org->id)
            ->where('status', Server::STATUS_READY)
            ->find($this->haproxy_server_id);

        if (! $haproxyServer) {
            $this->addError('haproxy_server_id', __('Server not found.'));
            return;
        }

        $lb = LoadBalancer::query()->create([
            'organization_id'    => $org->id,
            'server_id'          => $haproxyServer->id,
            'name'               => trim($this->lb_name),
            'provider'           => LoadBalancer::PROVIDER_HAPROXY,
            'region'             => $haproxyServer->region,
            'load_balancer_type' => 'haproxy',
            'algorithm'          => $this->lb_algorithm,
            'status'             => LoadBalancer::STATUS_PROVISIONING,
        ]);

        foreach (array_filter(array_unique($this->lb_target_server_ids)) as $serverId) {
            $s = Server::query()->where('organization_id', $org->id)->find($serverId);
            if ($s) {
                LoadBalancerTarget::query()->create([
                    'load_balancer_id' => $lb->id,
                    'server_id'        => $s->id,
                ]);
            }
        }

        $this->seedLbServices($lb);
        \App\Jobs\ConfigureHAProxyLoadBalancerJob::dispatch($lb->id);

        $this->dispatch('close-modal', 'org-create-haproxy-lb-modal');
        $this->resetLbForm();
        $this->toastSuccess(__('Software load balancer ":name" being configured on :server.', [
            'name'   => $lb->name,
            'server' => $haproxyServer->name,
        ]));
    }

    /**
     * Provision a managed Hetzner load balancer. Region + credential are derived
     * from the selected target servers (all must be Hetzner).
     */
    public function createHetznerLoadBalancer(): void
    {
        $this->validate($this->lbServiceRules() + [
            'lb_type'              => 'required|in:'.implode(',', LoadBalancer::TYPES),
            'lb_target_server_ids' => 'required|array|min:1',
        ]);

        $org = Auth::user()->currentOrganization();

        $targets = Server::query()
            ->where('organization_id', $org->id)
            ->whereIn('id', array_filter(array_unique($this->lb_target_server_ids)))
            ->get();

        $anchor = $targets->first(fn ($s) => $s->provider->value === 'hetzner');
        if (! $anchor || ! $anchor->providerCredential) {
            $this->addError('lb_target_server_ids', __('Select at least one Hetzner server — managed load balancers live in your Hetzner account.'));
            return;
        }

        $lb = LoadBalancer::query()->create([
            'organization_id'        => $org->id,
            'provider_credential_id' => $anchor->providerCredential->id,
            'name'                   => trim($this->lb_name),
            'provider'               => 'hetzner',
            'region'                 => $anchor->region,
            'load_balancer_type'     => $this->lb_type,
            'algorithm'              => $this->lb_algorithm,
            'status'                 => LoadBalancer::STATUS_PROVISIONING,
            'hetzner_network_id'     => $this->lb_network_id !== '' ? $this->lb_network_id : $anchor->hetzner_network_id,
        ]);

        foreach ($targets as $s) {
            LoadBalancerTarget::query()->create([
                'load_balancer_id'   => $lb->id,
                'server_id'          => $s->id,
                'provider_server_id' => $s->provider_id,
            ]);
        }

        $this->seedLbServices($lb);
        \App\Jobs\ProvisionHetznerLoadBalancerJob::dispatch($lb->id);

        $this->dispatch('close-modal', 'org-create-hetzner-lb-modal');
        $this->resetLbForm();
        $this->toastSuccess(__('Load balancer ":name" is being provisioned — it will appear here once the IP is assigned (~30 s).', ['name' => $lb->name]));
    }

    // ── Load balancer actions ─────────────────────────────────────────────────

    public function deleteLoadBalancer(string $lbId): void
    {
        $org = Auth::user()->currentOrganization();
        $lb = LoadBalancer::query()->where('organization_id', $org->id)->find($lbId);
        if (! $lb) { return; }

        if ($lb->isSoftware()) {
            \App\Jobs\ConfigureHAProxyLoadBalancerJob::dispatch($lb->id, remove: true);
        } elseif ($lb->provider_id && $lb->providerCredential) {
            try {
                (new HetznerService($lb->providerCredential))->deleteLoadBalancer((int) $lb->provider_id);
            } catch (\Throwable $e) {
                $this->toastError(Str::limit($e->getMessage(), 200));
                return;
            }
        }

        $lb->delete();
        $this->toastSuccess(__('Load balancer deleted.'));
    }

    // ── Network actions ───────────────────────────────────────────────────────

    public function createNetwork(): void
    {
        $this->validate([
            'net_name'          => 'required|string|max:255',
            'net_ip_range'      => 'required|string',
            'net_credential_id' => 'required|string',
        ]);

        $org = Auth::user()->currentOrganization();

        $credential = \App\Models\ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'hetzner')
            ->find($this->net_credential_id);

        if (! $credential) {
            $this->addError('net_credential_id', __('Credential not found.'));
            return;
        }

        $network = PrivateNetwork::query()->create([
            'organization_id'        => $org->id,
            'provider_credential_id' => $credential->id,
            'name'                   => trim($this->net_name),
            'provider'               => PrivateNetwork::PROVIDER_HETZNER,
            'ip_range'               => trim($this->net_ip_range),
        ]);

        \App\Jobs\CreateProviderNetworkJob::dispatch(
            $credential->id,
            $this->net_name,
            $this->net_ip_range,
            $this->net_server_ids,
            $network->id,
        );

        $this->dispatch('close-modal', 'create-network-modal');
        $this->net_name = '';
        $this->net_server_ids = [];

        $this->toastSuccess(__('Creating network ":name" — private IPs will appear once assigned (~30 s).', ['name' => $network->name]));
    }

    public function deleteNetwork(string $networkId): void
    {
        $org = Auth::user()->currentOrganization();
        $network = PrivateNetwork::query()->where('organization_id', $org->id)->with('servers')->find($networkId);
        if (! $network) { return; }

        // Detach all servers from this network first.
        foreach ($network->servers as $server) {
            if ($network->hetznerNetworkId() && $server->provider_id) {
                try {
                    (new HetznerService($network->providerCredential))
                        ->attachServerToNetwork((int) $server->provider_id, $network->hetznerNetworkId());
                } catch (\Throwable) {}
            }
            $server->update(['private_network_id' => null, 'private_ip_address' => null, 'hetzner_network_id' => null]);
        }

        $network->delete();
        $this->toastSuccess(__('Network deleted.'));
    }

    /** Per-network server picker model (networkId => serverId to attach). */
    public array $attach_server_id = [];

    /**
     * Attach an existing server to an existing network — gives it a private IP
     * on that network so it can reach the network's other members (database,
     * cache, etc.). Hetzner-only; the private IP appears once assigned (~30 s).
     */
    public function addServerToNetwork(string $networkId): void
    {
        $org = Auth::user()->currentOrganization();
        $network = PrivateNetwork::query()->where('organization_id', $org->id)->with('servers')->find($networkId);
        if (! $network || ! $network->hetznerNetworkId()) {
            return;
        }

        $serverId = trim((string) ($this->attach_server_id[$networkId] ?? ''));
        $server = Server::query()->where('organization_id', $org->id)->find($serverId);
        if (! $server instanceof Server) {
            $this->addError('attach_server_id.'.$networkId, __('Choose a server to attach.'));

            return;
        }
        if ($network->servers->contains('id', $server->id)) {
            $this->addError('attach_server_id.'.$networkId, __(':server is already on this network.', ['server' => $server->name]));

            return;
        }

        AttachServerToNetworkJob::dispatch((string) $server->id, $network->hetznerNetworkId(), (string) $network->id);

        $this->attach_server_id[$networkId] = '';
        $this->toastSuccess(__('Attaching :server to :net — its private IP appears in ~30 s.', ['server' => $server->name, 'net' => $network->name]));
    }

    /**
     * Org Hetzner servers not yet on the given network (the attach candidates).
     *
     * @return \Illuminate\Support\Collection<int, Server>
     */
    public function attachableServers(PrivateNetwork $network): \Illuminate\Support\Collection
    {
        $org = Auth::user()->currentOrganization();
        $onNet = $network->servers->pluck('id');

        return Server::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'hetzner')
            ->whereNotIn('id', $onNet)
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);
    }

    // ── Route actions (per-network) ───────────────────────────────────────────

    public function addRoute(string $networkId): void
    {
        $org = Auth::user()->currentOrganization();
        $network = PrivateNetwork::query()->where('organization_id', $org->id)->with('servers')->find($networkId);
        if (! $network || ! $network->hetznerNetworkId()) { return; }

        $destination = trim($this->route_destination[$networkId] ?? '');

        if (! str_contains($destination, '/') || ! filter_var(explode('/', $destination)[0], FILTER_VALIDATE_IP)) {
            $this->addError("route_destination.$networkId", __("That doesn't look like a valid range — try 192.168.1.0/24."));
            return;
        }

        $server = $network->servers->firstWhere('id', $this->route_gateway_server[$networkId] ?? '');
        if (! $server || ! $server->private_ip_address) {
            $this->addError("route_gateway_server.$networkId", __('Choose a server on this network to act as the gateway.'));
            return;
        }
        $gateway = $server->private_ip_address;

        try {
            (new HetznerService($network->providerCredential))
                ->addNetworkRoute($network->hetznerNetworkId(), $destination, $gateway);

            $this->route_destination[$networkId]    = '';
            $this->route_gateway_server[$networkId] = '';
            $this->toastSuccess(__('Route :dest → :gw (:name) added.', ['dest' => $destination, 'gw' => $gateway, 'name' => $server->name]));
        } catch (\Throwable $e) {
            $this->toastError(Str::limit($e->getMessage(), 200));
        }
    }

    public function deleteRoute(string $networkId, string $destination, string $gateway): void
    {
        $org = Auth::user()->currentOrganization();
        $network = PrivateNetwork::query()->where('organization_id', $org->id)->find($networkId);
        if (! $network || ! $network->hetznerNetworkId()) { return; }

        try {
            (new HetznerService($network->providerCredential))
                ->deleteNetworkRoute($network->hetznerNetworkId(), $destination, $gateway);
            $this->toastSuccess(__('Route :dest removed.', ['dest' => $destination]));
        } catch (\Throwable $e) {
            $this->toastError(Str::limit($e->getMessage(), 200));
        }
    }

    public function render(): View
    {
        $org = Auth::user()->currentOrganization();

        $loadBalancers = LoadBalancer::query()
            ->where('organization_id', $org->id)
            ->with(['targets.server', 'services', 'server'])
            ->orderBy('created_at', 'desc')
            ->get();

        $networks = PrivateNetwork::query()
            ->where('organization_id', $org->id)
            ->with(['servers', 'providerCredential'])
            ->orderBy('name')
            ->get();

        // Fetch live routes for Hetzner networks.
        $routesByNetwork = [];
        foreach ($networks->where('provider', 'hetzner') as $network) {
            $id = $network->hetznerNetworkId();
            if ($id && $network->providerCredential) {
                try {
                    $data = (new HetznerService($network->providerCredential))->getNetwork($id);
                    $routesByNetwork[$network->id] = $data['routes'] ?? [];
                    // Backfill ip_range if we didn't have it.
                    if (! $network->ip_range && filled($data['ip_range'])) {
                        $network->update(['ip_range' => $data['ip_range'], 'name' => $data['name']]);
                    }
                } catch (\Throwable) {
                    $routesByNetwork[$network->id] = [];
                }
            }
        }

        $orgServers = Server::query()
            ->where('organization_id', $org->id)
            ->where('status', Server::STATUS_READY)
            ->orderBy('name')
            ->get();

        $hetznerCredentials = \App\Models\ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'hetzner')
            ->orderBy('name')
            ->get();

        return view('livewire.org-networking', [
            'loadBalancers'      => $loadBalancers,
            'networks'           => $networks,
            'routesByNetwork'    => $routesByNetwork,
            'orgServers'         => $orgServers,
            'hetznerCredentials' => $hetznerCredentials,
        ]);
    }

    private function toastSuccess(string $message): void
    {
        $this->dispatch('toast', type: 'success', message: $message);
    }

    private function toastError(string $message): void
    {
        $this->dispatch('toast', type: 'error', message: $message);
    }
}
