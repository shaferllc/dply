<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Jobs\ProvisionHetznerLoadBalancerJob;
use App\Jobs\ReconcileSiteBackendBalancerJob;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerService;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerProvisioningDispatcher;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions the load balancer that fronts a multi-backend site and links it to
 * the group (`meta.backend_group.load_balancer_id`). This is what lights up the
 * whole multi-backend path: until a balancer is linked, {@see SiteBackendBalancerSync}
 * no-ops and the rolling/canary methods stay hidden.
 *
 *  - `haproxy`  → a dedicated HAProxy host (server_role=load_balancer installs
 *                 HAProxy at provision time) + a LoadBalancer row; once the host
 *                 is ready {@see ReconcileSiteBackendBalancerJob} writes the
 *                 config and registers the active backends.
 *  - `hetzner`  → a provider cloud LB via the existing
 *                 {@see ProvisionHetznerLoadBalancerJob}.
 *
 * v1 fronts HTTP :80 (the site's webserver port). TLS termination at the LB and
 * DNS pointing are follow-ups. See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackendBalancerProvisioner
{
    public function __construct(
        private readonly ServerProvisioningDispatcher $provisioning,
    ) {}

    /**
     * Provision + link a balancer for the site's group. Idempotent: returns the
     * existing LB if one is already linked.
     */
    public function provision(Site $site): LoadBalancer
    {
        $group = $site->backendGroup();
        $existingId = (string) ($group['load_balancer_id'] ?? '');
        if ($existingId !== '') {
            $existing = LoadBalancer::query()->find($existingId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $source = $site->server;
        if ($source === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $substrate = ($group['substrate'] ?? null) === SiteBackendManager::SUBSTRATE_HETZNER
            ? SiteBackendManager::SUBSTRATE_HETZNER
            : SiteBackendManager::SUBSTRATE_HAPROXY;

        $lb = $substrate === SiteBackendManager::SUBSTRATE_HETZNER
            ? $this->provisionHetzner($site, $source)
            : $this->provisionHaproxy($site, $source);

        $this->linkToGroup($site, $lb);

        return $lb;
    }

    private function provisionHaproxy(Site $site, Server $source): LoadBalancer
    {
        // Dedicated HAProxy host — server_role=load_balancer makes provisioning
        // install + enable HAProxy. Cloned placement so it sits next to (and, for
        // Hetzner, on the same private network as) the backends.
        $meta = ['server_role' => 'load_balancer'];
        $sourceMeta = is_array($source->meta) ? $source->meta : [];
        foreach (['install_profile', 'os_image', 'digitalocean'] as $key) {
            if (array_key_exists($key, $sourceMeta)) {
                $meta[$key] = $sourceMeta[$key];
            }
        }

        $host = Server::query()->create([
            'user_id' => $source->user_id,
            'organization_id' => $source->organization_id,
            'name' => $this->lbHostName($source),
            'provider' => $source->provider,
            'hosting_backend' => $source->hosting_backend,
            'provider_credential_id' => $source->provider_credential_id,
            'region' => $source->region,
            'size' => $source->size,
            'hetzner_network_id' => $source->hetzner_network_id,
            'private_network_id' => $source->private_network_id,
            'ssh_port' => $source->ssh_port,
            'ssh_user' => $source->ssh_user,
            'setup_script_key' => $source->setup_script_key,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $lb = LoadBalancer::query()->create([
            'organization_id' => $source->organization_id,
            'server_id' => $host->id,
            'name' => $this->lbName($site),
            'provider' => LoadBalancer::PROVIDER_HAPROXY,
            'region' => $source->region,
            'load_balancer_type' => 'haproxy',
            'algorithm' => LoadBalancer::ALGORITHM_ROUND_ROBIN,
            'status' => LoadBalancer::STATUS_PROVISIONING,
        ]);

        $this->seedHttpService($lb);
        $this->provisioning->dispatch($host);

        // Wait for the HAProxy host to come up, then write config + register the
        // active backends.
        ReconcileSiteBackendBalancerJob::dispatch((string) $lb->id);

        return $lb;
    }

    private function provisionHetzner(Site $site, Server $source): LoadBalancer
    {
        if ($source->provider_credential_id === null) {
            throw new RuntimeException(__('A Hetzner provider credential is required for a cloud load balancer.'));
        }

        $lb = LoadBalancer::query()->create([
            'organization_id' => $source->organization_id,
            'provider_credential_id' => $source->provider_credential_id,
            'name' => $this->lbName($site),
            'provider' => LoadBalancer::PROVIDER_HETZNER,
            'region' => $source->region,
            'load_balancer_type' => 'lb11',
            'algorithm' => LoadBalancer::ALGORITHM_ROUND_ROBIN,
            'status' => LoadBalancer::STATUS_PROVISIONING,
            'hetzner_network_id' => $source->hetzner_network_id,
        ]);

        $this->seedHttpService($lb);

        // Existing job creates the cloud LB + services; backends are registered
        // afterwards by SiteBackendBalancerSync (→ SyncHetznerLoadBalancerTargetsJob).
        ProvisionHetznerLoadBalancerJob::dispatch($lb->id);

        return $lb;
    }

    private function seedHttpService(LoadBalancer $lb): void
    {
        // v1: balance plain HTTP on :80 to each backend's webserver port 80.
        LoadBalancerService::query()->create([
            'load_balancer_id' => $lb->id,
            'protocol' => 'http',
            'listen_port' => 80,
            'destination_port' => 80,
            'health_check_port' => 80,
            'health_check_protocol' => 'http',
        ]);
    }

    private function linkToGroup(Site $site, LoadBalancer $lb): void
    {
        $meta = ($site->meta );
        $group = is_array($meta['backend_group'] ?? null) ? $meta['backend_group'] : [];
        $group['load_balancer_id'] = (string) $lb->id;
        $meta['backend_group'] = $group;
        $site->forceFill(['meta' => $meta])->save();
    }

    private function lbName(Site $site): string
    {
        return (Str::slug((string) $site->name) ?: 'site').'-lb';
    }

    private function lbHostName(Server $source): string
    {
        $stem = (string) preg_replace('/-be-\d+$/', '', (string) $source->name);

        return ($stem !== '' ? $stem : 'app').'-lb';
    }
}
