<?php

namespace App\Livewire\Concerns;

use App\Models\ProviderCredential;
use App\Services\AwsEc2Service;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use App\Support\ServerProviderGate;

trait ManagesProviderCredentials
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    /** When set, the credentials panel shows a working state on that row only. */
    public ?string $verifyingCredentialId = null;

    public string $do_name = '';

    public string $do_api_token = '';

    public string $cloudflare_name = '';

    public string $cloudflare_api_token = '';

    public string $hetzner_name = '';

    public string $hetzner_api_token = '';

    public string $linode_name = '';

    public string $linode_api_token = '';

    public string $vultr_name = '';

    public string $vultr_api_token = '';

    public string $akamai_name = '';

    public string $akamai_api_token = '';

    public string $equinix_metal_name = '';

    public string $equinix_metal_api_token = '';

    public string $equinix_metal_project_id = '';

    public string $upcloud_name = '';

    public string $upcloud_username = '';

    public string $upcloud_password = '';

    public string $scaleway_name = '';

    public string $scaleway_api_token = '';

    public string $scaleway_project_id = '';

    public string $ovh_name = '';

    public string $ovh_api_token = '';

    public string $rackspace_name = '';

    public string $rackspace_api_token = '';

    public string $fly_io_name = '';

    public string $fly_io_api_token = '';

    public string $fly_io_org_slug = 'personal';

    public string $render_name = '';

    public string $render_api_token = '';

    public string $railway_name = '';

    public string $railway_api_token = '';

    public string $coolify_name = '';

    public string $coolify_api_url = '';

    public string $coolify_api_token = '';

    public string $cap_rover_name = '';

    public string $cap_rover_api_url = '';

    public string $cap_rover_api_token = '';

    public string $aws_name = '';

    public string $aws_access_key_id = '';

    public string $aws_secret_access_key = '';

    public string $gcp_name = '';

    public string $gcp_api_token = '';

    public string $azure_name = '';

    public string $azure_api_token = '';

    public string $oracle_name = '';

    public string $oracle_api_token = '';

    public function storeDigitalOcean(): void
    {
        if (! $this->ensureProviderEnabled('digitalocean')) {
            return;
        }
        $this->validate([
            'do_name' => 'nullable|string|max:255',
            'do_api_token' => 'required|string',
        ], [], ['do_api_token' => 'API token']);
        if ($this->storeProviderCredential('digitalocean', $this->do_name, $this->do_api_token, 'do_api_token')) {
            $this->reset('do_name', 'do_api_token');
        }
    }

    public function storeCloudflare(): void
    {
        if (! $this->ensureProviderEnabled('cloudflare')) {
            return;
        }
        $this->validate([
            'cloudflare_name' => 'nullable|string|max:255',
            'cloudflare_api_token' => 'required|string',
        ], [], ['cloudflare_api_token' => 'API token']);
        if ($this->storeProviderCredential('cloudflare', $this->cloudflare_name, $this->cloudflare_api_token, 'cloudflare_api_token')) {
            $this->reset('cloudflare_name', 'cloudflare_api_token');
        }
    }

    public function storeHetzner(): void
    {
        if (! $this->ensureProviderEnabled('hetzner')) {
            return;
        }
        $this->validate([
            'hetzner_name' => 'nullable|string|max:255',
            'hetzner_api_token' => 'required|string',
        ], [], ['hetzner_api_token' => 'API token']);
        if ($this->storeProviderCredential('hetzner', $this->hetzner_name, $this->hetzner_api_token, 'hetzner_api_token')) {
            $this->reset('hetzner_name', 'hetzner_api_token');
        }
    }

    public function storeLinode(): void
    {
        if (! $this->ensureProviderEnabled('linode')) {
            return;
        }
        $this->validate([
            'linode_name' => 'nullable|string|max:255',
            'linode_api_token' => 'required|string',
        ], [], ['linode_api_token' => 'API token']);
        if ($this->storeProviderCredential('linode', $this->linode_name, $this->linode_api_token, 'linode_api_token')) {
            $this->reset('linode_name', 'linode_api_token');
        }
    }

    public function storeVultr(): void
    {
        if (! $this->ensureProviderEnabled('vultr')) {
            return;
        }
        $this->validate([
            'vultr_name' => 'nullable|string|max:255',
            'vultr_api_token' => 'required|string',
        ], [], ['vultr_api_token' => 'API token']);
        if ($this->storeProviderCredential('vultr', $this->vultr_name, $this->vultr_api_token, 'vultr_api_token')) {
            $this->reset('vultr_name', 'vultr_api_token');
        }
    }

    public function storeAkamai(): void
    {
        if (! $this->ensureProviderEnabled('akamai')) {
            return;
        }
        $this->validate([
            'akamai_name' => 'nullable|string|max:255',
            'akamai_api_token' => 'required|string',
        ], [], ['akamai_api_token' => 'API token']);
        if ($this->storeProviderCredential('akamai', $this->akamai_name, $this->akamai_api_token, 'akamai_api_token')) {
            $this->reset('akamai_name', 'akamai_api_token');
        }
    }

    public function storeEquinixMetal(): void
    {
        if (! $this->ensureProviderEnabled('equinix_metal')) {
            return;
        }
        $this->validate([
            'equinix_metal_name' => 'nullable|string|max:255',
            'equinix_metal_api_token' => 'required|string',
            'equinix_metal_project_id' => 'required|string|max:255',
        ], [], [
            'equinix_metal_api_token' => 'API token',
            'equinix_metal_project_id' => 'Project ID',
        ]);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'equinix_metal',
            'name' => trim($this->equinix_metal_name) ?: 'Equinix Metal',
            'credentials' => [
                'api_token' => $this->equinix_metal_api_token,
                'project_id' => $this->equinix_metal_project_id,
            ],
        ]);
        try {
            $metal = new EquinixMetalService($credential);
            $metal->validateToken();
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid token/project or API error: '.$e->getMessage();
            $this->addError('equinix_metal_api_token', $msg);
            $this->toastError($msg);

            return;
        }
        $this->toastSuccess('Provider connected.');
        $this->reset('equinix_metal_name', 'equinix_metal_api_token', 'equinix_metal_project_id');
        $this->notifyProviderCredentialStored('equinix_metal');
    }

    public function storeUpCloud(): void
    {
        if (! $this->ensureProviderEnabled('upcloud')) {
            return;
        }
        $this->validate([
            'upcloud_name' => 'nullable|string|max:255',
            'upcloud_username' => 'required|string|max:255',
            'upcloud_password' => 'required|string',
        ], [], [
            'upcloud_username' => 'API username',
            'upcloud_password' => 'API password',
        ]);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'upcloud',
            'name' => trim($this->upcloud_name) ?: 'UpCloud',
            'credentials' => [
                'api_username' => $this->upcloud_username,
                'api_password' => $this->upcloud_password,
            ],
        ]);
        try {
            $upcloud = new UpCloudService($credential);
            $upcloud->validateToken();
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid credentials or API error: '.$e->getMessage();
            $this->addError('upcloud_username', $msg);
            $this->toastError($msg);

            return;
        }
        $this->toastSuccess('Provider connected.');
        $this->reset('upcloud_name', 'upcloud_username', 'upcloud_password');
        $this->notifyProviderCredentialStored('upcloud');
    }

    public function storeScaleway(): void
    {
        if (! $this->ensureProviderEnabled('scaleway')) {
            return;
        }
        $this->validate([
            'scaleway_name' => 'nullable|string|max:255',
            'scaleway_api_token' => 'required|string',
            'scaleway_project_id' => 'required|string|max:255',
        ], [], [
            'scaleway_api_token' => 'API token',
            'scaleway_project_id' => 'Project ID',
        ]);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'scaleway',
            'name' => trim($this->scaleway_name) ?: 'Scaleway',
            'credentials' => [
                'api_token' => $this->scaleway_api_token,
                'project_id' => $this->scaleway_project_id,
            ],
        ]);
        try {
            $scw = new ScalewayService($credential);
            $scw->validateToken();
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid token/project or API error: '.$e->getMessage();
            $this->addError('scaleway_api_token', $msg);
            $this->toastError($msg);

            return;
        }
        $this->toastSuccess('Provider connected.');
        $this->reset('scaleway_name', 'scaleway_api_token', 'scaleway_project_id');
        $this->notifyProviderCredentialStored('scaleway');
    }

    public function storeOvh(): void
    {
        if (! $this->ensureProviderEnabled('ovh')) {
            return;
        }
        $this->validate([
            'ovh_name' => 'nullable|string|max:255',
            'ovh_api_token' => 'required|string',
        ], [], ['ovh_api_token' => 'API token']);
        if ($this->storeProviderCredential('ovh', $this->ovh_name, $this->ovh_api_token, 'ovh_api_token')) {
            $this->reset('ovh_name', 'ovh_api_token');
        }
    }

    public function storeRackspace(): void
    {
        if (! $this->ensureProviderEnabled('rackspace')) {
            return;
        }
        $this->validate([
            'rackspace_name' => 'nullable|string|max:255',
            'rackspace_api_token' => 'required|string',
        ], [], ['rackspace_api_token' => 'API key']);
        if ($this->storeProviderCredential('rackspace', $this->rackspace_name, $this->rackspace_api_token, 'rackspace_api_token')) {
            $this->reset('rackspace_name', 'rackspace_api_token');
        }
    }

    public function storeFlyIo(): void
    {
        if (! $this->ensureProviderEnabled('fly_io')) {
            return;
        }
        $this->validate([
            'fly_io_name' => 'nullable|string|max:255',
            'fly_io_api_token' => 'required|string',
            'fly_io_org_slug' => 'required|string|max:100',
        ], [], [
            'fly_io_api_token' => 'API token',
            'fly_io_org_slug' => 'Organization slug',
        ]);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'fly_io',
            'name' => trim($this->fly_io_name) ?: 'Fly.io',
            'credentials' => [
                'api_token' => $this->fly_io_api_token,
                'org_slug' => $this->fly_io_org_slug,
            ],
        ]);
        try {
            $fly = new FlyIoService($credential);
            $fly->validateToken($this->fly_io_org_slug);
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid token or API error: '.$e->getMessage();
            $this->addError('fly_io_api_token', $msg);
            $this->toastError($msg);

            return;
        }
        $this->toastSuccess('Provider connected.');
        $this->reset('fly_io_name', 'fly_io_api_token', 'fly_io_org_slug');
        $this->notifyProviderCredentialStored('fly_io');
    }

    public function storeRender(): void
    {
        if (! $this->ensureProviderEnabled('render')) {
            return;
        }
        $this->validate(['render_name' => 'nullable|string|max:255', 'render_api_token' => 'required|string'], [], ['render_api_token' => 'API token']);
        if ($this->storeProviderCredential('render', $this->render_name, $this->render_api_token, 'render_api_token')) {
            $this->reset('render_name', 'render_api_token');
        }
    }

    public function storeRailway(): void
    {
        if (! $this->ensureProviderEnabled('railway')) {
            return;
        }
        $this->validate(['railway_name' => 'nullable|string|max:255', 'railway_api_token' => 'required|string'], [], ['railway_api_token' => 'API token']);
        if ($this->storeProviderCredential('railway', $this->railway_name, $this->railway_api_token, 'railway_api_token')) {
            $this->reset('railway_name', 'railway_api_token');
        }
    }

    public function storeCoolify(): void
    {
        if (! $this->ensureProviderEnabled('coolify')) {
            return;
        }
        $this->validate([
            'coolify_name' => 'nullable|string|max:255',
            'coolify_api_url' => 'required|string|max:500',
            'coolify_api_token' => 'required|string',
        ], [], ['coolify_api_url' => 'Coolify server URL', 'coolify_api_token' => 'API token']);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'coolify',
            'name' => trim($this->coolify_name) ?: 'Coolify',
            'credentials' => ['api_url' => rtrim($this->coolify_api_url, '/'), 'api_token' => $this->coolify_api_token],
        ]);
        $this->toastSuccess('Credential saved. Server create/destroy not yet implemented.');
        $this->reset('coolify_name', 'coolify_api_url', 'coolify_api_token');
        $this->notifyProviderCredentialStored('coolify');
    }

    public function storeCapRover(): void
    {
        if (! $this->ensureProviderEnabled('cap_rover')) {
            return;
        }
        $this->validate([
            'cap_rover_name' => 'nullable|string|max:255',
            'cap_rover_api_url' => 'required|string|max:500',
            'cap_rover_api_token' => 'required|string',
        ], [], ['cap_rover_api_url' => 'CapRover server URL', 'cap_rover_api_token' => 'API token']);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'cap_rover',
            'name' => trim($this->cap_rover_name) ?: 'CapRover',
            'credentials' => ['api_url' => rtrim($this->cap_rover_api_url, '/'), 'api_token' => $this->cap_rover_api_token],
        ]);
        $this->toastSuccess('Credential saved. Server create/destroy not yet implemented.');
        $this->reset('cap_rover_name', 'cap_rover_api_url', 'cap_rover_api_token');
        $this->notifyProviderCredentialStored('cap_rover');
    }

    public function storeAws(): void
    {
        if (! $this->ensureProviderEnabled('aws')) {
            return;
        }
        $this->validate([
            'aws_name' => 'nullable|string|max:255',
            'aws_access_key_id' => 'required|string|max:255',
            'aws_secret_access_key' => 'required|string',
        ], [], ['aws_access_key_id' => 'Access key ID', 'aws_secret_access_key' => 'Secret access key']);
        $this->authorize('create', ProviderCredential::class);
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return;
        }
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => 'aws',
            'name' => trim($this->aws_name) ?: 'AWS',
            'credentials' => ['access_key_id' => $this->aws_access_key_id, 'secret_access_key' => $this->aws_secret_access_key],
        ]);
        try {
            $aws = new AwsEc2Service($credential);
            $aws->validateCredentials();
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid credentials or API error: '.$e->getMessage();
            $this->addError('aws_access_key_id', $msg);
            $this->toastError($msg);

            return;
        }
        $this->toastSuccess('Provider connected.');
        $this->reset('aws_name', 'aws_access_key_id', 'aws_secret_access_key');
        $this->notifyProviderCredentialStored('aws');
    }

    public function storeGcp(): void
    {
        if (! $this->ensureProviderEnabled('gcp')) {
            return;
        }
        $this->validate(['gcp_name' => 'nullable|string|max:255', 'gcp_api_token' => 'required|string'], [], ['gcp_api_token' => 'API token']);
        if ($this->storeProviderCredential('gcp', $this->gcp_name, $this->gcp_api_token, 'gcp_api_token')) {
            $this->reset('gcp_name', 'gcp_api_token');
        }
    }

    public function storeAzure(): void
    {
        if (! $this->ensureProviderEnabled('azure')) {
            return;
        }
        $this->validate(['azure_name' => 'nullable|string|max:255', 'azure_api_token' => 'required|string'], [], ['azure_api_token' => 'API token']);
        if ($this->storeProviderCredential('azure', $this->azure_name, $this->azure_api_token, 'azure_api_token')) {
            $this->reset('azure_name', 'azure_api_token');
        }
    }

    public function storeOracle(): void
    {
        if (! $this->ensureProviderEnabled('oracle')) {
            return;
        }
        $this->validate(['oracle_name' => 'nullable|string|max:255', 'oracle_api_token' => 'required|string'], [], ['oracle_api_token' => 'API token']);
        if ($this->storeProviderCredential('oracle', $this->oracle_name, $this->oracle_api_token, 'oracle_api_token')) {
            $this->reset('oracle_name', 'oracle_api_token');
        }
    }

    protected function ensureProviderEnabled(string $provider): bool
    {
        if (ServerProviderGate::enabled($provider)) {
            return true;
        }

        $this->toastError(__('This provider is not available yet.'));

        return false;
    }

    protected function storeProviderCredential(string $provider, string $name, string $apiToken, string $tokenErrorKey): bool
    {
        $this->authorize('create', ProviderCredential::class);

        $org = auth()->user()->currentOrganization();
        if (! $org) {
            $this->toastError('Select or create an organization first.');

            return false;
        }

        $defaultNames = [
            'digitalocean' => 'DigitalOcean', 'cloudflare' => 'Cloudflare', 'hetzner' => 'Hetzner', 'linode' => 'Linode', 'vultr' => 'Vultr',
            'akamai' => 'Akamai', 'ovh' => 'OVH', 'rackspace' => 'Rackspace', 'render' => 'Render', 'railway' => 'Railway',
            'gcp' => 'GCP', 'azure' => 'Azure', 'oracle' => 'Oracle Cloud',
        ];
        $credential = auth()->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => $provider,
            'name' => trim($name) ?: ($defaultNames[$provider] ?? ucfirst($provider)),
            'credentials' => ['api_token' => $apiToken],
        ]);

        try {
            if ($provider === 'digitalocean') {
                $do = new DigitalOceanService($credential);
                $do->getDroplets();
            } elseif ($provider === 'hetzner') {
                $hetzner = new HetznerService($credential);
                $hetzner->validateToken();
            } elseif ($provider === 'linode' || $provider === 'akamai') {
                $linode = new LinodeService($credential);
                $linode->validateToken();
            } elseif ($provider === 'vultr') {
                $vultr = new VultrService($credential);
                $vultr->validateToken();
            } elseif ($provider === 'cloudflare') {
                (new CloudflareDnsService($credential))->verifyToken();
            } elseif (in_array($provider, ['ovh', 'rackspace', 'render', 'railway', 'gcp', 'azure', 'oracle'], true)) {
                // No validation service yet; credential saved for future use
            } else {
                throw new \InvalidArgumentException("Unknown provider: {$provider}");
            }
        } catch (\Throwable $e) {
            $credential->delete();
            $msg = 'Invalid token or API error: '.$e->getMessage();
            $this->addError($tokenErrorKey, $msg);
            $this->toastError($msg);

            return false;
        }

        $this->toastSuccess('Provider connected.');
        $this->notifyProviderCredentialStored($provider);

        return true;
    }

    protected function notifyProviderCredentialStored(string $provider): void
    {
        if (method_exists($this, 'afterProviderCredentialStored')) {
            $this->afterProviderCredentialStored($provider);
        }
    }

    public function canVerifyCredentialProvider(string $provider): bool
    {
        return in_array($provider, [
            'digitalocean', 'cloudflare', 'hetzner', 'linode', 'akamai', 'vultr',
            'equinix_metal', 'upcloud', 'scaleway', 'fly_io', 'aws',
        ], true);
    }

    public function verifyCredential(string $id): void
    {
        $this->verifyingCredentialId = $id;

        try {
            $credential = ProviderCredential::findOrFail($id);
            $this->authorize('view', $credential);

            if (! $this->canVerifyCredentialProvider($credential->provider)) {
                $this->toastError(__('API verification is not implemented for this provider yet.'));

                return;
            }

            match ($credential->provider) {
                // Light GET /account — confirms the token works (same check as when connecting).
                'digitalocean' => (new DigitalOceanService($credential))->validateToken(),
                'cloudflare' => (new CloudflareDnsService($credential))->verifyToken(),
                'hetzner' => (new HetznerService($credential))->validateToken(),
                'linode', 'akamai' => (new LinodeService($credential))->validateToken(),
                'vultr' => (new VultrService($credential))->validateToken(),
                'equinix_metal' => (new EquinixMetalService($credential))->validateToken(),
                'upcloud' => (new UpCloudService($credential))->validateToken(),
                'scaleway' => (new ScalewayService($credential))->validateToken(),
                'fly_io' => (new FlyIoService($credential))->validateToken($credential->credentials['org_slug'] ?? 'personal'),
                'aws' => (new AwsEc2Service($credential))->validateCredentials(),
                default => throw new \RuntimeException(__('Unknown provider.')),
            };

            $this->toastSuccess(__('Credentials verified with the provider API.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        } finally {
            $this->verifyingCredentialId = null;
        }
    }

    public function destroy(string|int $id): void
    {
        $credential = ProviderCredential::findOrFail($id);
        $this->authorize('delete', $credential);
        $credential->delete();
        $this->toastSuccess('Credential removed.');
    }
}
