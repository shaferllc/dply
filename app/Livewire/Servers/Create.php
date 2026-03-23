<?php

namespace App\Livewire\Servers;

use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionEquinixMetalServerJob;
use App\Jobs\ProvisionFlyIoServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionScalewayServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public string $type = 'digitalocean';

    public string $name = '';

    public string $provider_credential_id = '';

    public string $region = '';

    public string $size = '';

    public string $setup_script_key = '';

    public string $ip_address = '';

    public string $ssh_port = '22';

    public string $ssh_user = 'root';

    public string $ssh_private_key = '';

    public function store(): mixed
    {
        $user = auth()->user();
        if (! $user->hasVerifiedEmail()) {
            return $this->redirect(route('verification.notice'), navigate: true)
                ->with('error', __('Please verify your email address before creating a server.'));
        }

        $this->authorize('create', Server::class);

        $org = $user->currentOrganization();
        if (! $org) {
            $this->addError('org', 'Select or create an organization first.');
            return null;
        }
        if (! $org->canCreateServer()) {
            $this->addError('org', 'Server limit reached for your plan. Upgrade to add more.');
            return null;
        }

        $scriptKeys = array_keys(config('setup_scripts.scripts', []));

        if ($this->type === 'digitalocean') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::DigitalOcean,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionDigitalOceanDropletJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'hetzner') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'hetzner')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Hetzner,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionHetznerServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'linode') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'linode')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Linode,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionLinodeServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'vultr') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'vultr')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Vultr,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionVultrServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'akamai') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'akamai')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Akamai,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionLinodeServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'scaleway') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'scaleway')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Scaleway,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionScalewayServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'upcloud') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'upcloud')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::UpCloud,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionUpCloudServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'equinix_metal') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'equinix_metal')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::EquinixMetal,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionEquinixMetalServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Server is being created. Bare metal can take 5–10 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'fly_io') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'fly_io')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::FlyIo,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionFlyIoServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'Fly.io machine is being created.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        if ($this->type === 'aws') {
            $this->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'aws')
                ->findOrFail($this->provider_credential_id);

            $setupScriptKey = ! empty(trim($this->setup_script_key)) ? $this->setup_script_key : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $user->servers()->create([
                'organization_id' => $org->id,
                'name' => $this->name,
                'provider' => ServerProvider::Aws,
                'provider_credential_id' => $credential->id,
                'region' => $this->region,
                'size' => $this->size,
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionAwsEc2ServerJob::dispatch($server);
            audit_log($org, $user, 'server.created', $server);

            Session::flash('success', 'AWS EC2 instance is being created. This usually takes 1–2 minutes.');
            return $this->redirect(route('servers.show', $server), navigate: true);
        }

        // Custom
        $this->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|string|max:45',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_user' => 'required|string|max:255',
            'ssh_private_key' => 'required|string',
        ]);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $this->name,
            'provider' => ServerProvider::Custom,
            'ip_address' => $this->ip_address,
            'ssh_port' => (int) ($this->ssh_port ?: 22),
            'ssh_user' => $this->ssh_user,
            'ssh_private_key' => $this->ssh_private_key,
            'status' => Server::STATUS_READY,
        ]);

        audit_log($org, $user, 'server.created', $server);

        Session::flash('success', 'Server added.');
        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    public function render(): View
    {
        $this->authorize('create', \App\Models\Server::class);

        $org = auth()->user()->currentOrganization();
        $credentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'digitalocean')->get()
            : collect();
        $regions = $sizes = [];

        if ($credentials->isNotEmpty()) {
            try {
                $do = new DigitalOceanService($credentials->first());
                $regions = $do->getRegions();
                $sizes = $do->getSizes();
            } catch (\Throwable) {
                //
            }
        }

        $hetznerCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'hetzner')->get()
            : collect();
        $hetznerLocations = [];
        $hetznerSizes = [];
        if ($hetznerCredentials->isNotEmpty()) {
            try {
                $hetzner = new HetznerService($hetznerCredentials->first());
                $hetznerLocations = $hetzner->getLocations();
                $hetznerSizes = $hetzner->getServerTypes();
            } catch (\Throwable) {
                //
            }
        }

        $linodeCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'linode')->get()
            : collect();
        $linodeRegions = [];
        $linodeTypes = [];
        if ($linodeCredentials->isNotEmpty()) {
            try {
                $linode = new LinodeService($linodeCredentials->first());
                $linodeRegions = $linode->getRegions();
                $linodeTypes = $linode->getTypes();
            } catch (\Throwable) {
                //
            }
        }

        $vultrCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'vultr')->get()
            : collect();
        $vultrRegions = [];
        $vultrPlans = [];
        if ($vultrCredentials->isNotEmpty()) {
            try {
                $vultr = new VultrService($vultrCredentials->first());
                $vultrRegions = $vultr->getRegions();
                $vultrPlans = $vultr->getPlans();
            } catch (\Throwable) {
                //
            }
        }

        $akamaiCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'akamai')->get()
            : collect();
        $akamaiRegions = [];
        $akamaiTypes = [];
        if ($akamaiCredentials->isNotEmpty()) {
            try {
                $linode = new LinodeService($akamaiCredentials->first());
                $akamaiRegions = $linode->getRegions();
                $akamaiTypes = $linode->getTypes();
            } catch (\Throwable) {
                //
            }
        }

        $scalewayCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'scaleway')->get()
            : collect();
        $scalewayZones = [];
        $scalewayTypes = [];
        if ($scalewayCredentials->isNotEmpty()) {
            try {
                $scw = new ScalewayService($scalewayCredentials->first());
                $scalewayZones = $scw->getZones();
                $scalewayTypes = $scw->getServerTypes('fr-par-1');
            } catch (\Throwable) {
                //
            }
        }

        $upcloudCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'upcloud')->get()
            : collect();
        $upcloudZones = [];
        $upcloudPlans = [];
        if ($upcloudCredentials->isNotEmpty()) {
            try {
                $upcloud = new UpCloudService($upcloudCredentials->first());
                $upcloudZones = $upcloud->getZones();
                $upcloudPlans = $upcloud->getPlans();
            } catch (\Throwable) {
                //
            }
        }

        $equinixMetalCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'equinix_metal')->get()
            : collect();
        $equinixMetalPlans = [];
        $equinixMetalMetros = [];
        if ($equinixMetalCredentials->isNotEmpty()) {
            try {
                $metal = new EquinixMetalService($equinixMetalCredentials->first());
                $equinixMetalPlans = $metal->getPlans();
                $equinixMetalMetros = $metal->getMetros();
            } catch (\Throwable) {
                //
            }
        }

        $flyIoCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'fly_io')->get()
            : collect();
        $flyIoRegions = FlyIoService::getRegions();
        $flyIoVmSizes = FlyIoService::getVmSizes();

        $awsCredentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->where('provider', 'aws')->get()
            : collect();
        $awsRegions = AwsEc2Service::getDefaultRegions();
        $awsInstanceTypes = AwsEc2Service::getInstanceTypes();
        if ($awsCredentials->isNotEmpty()) {
            try {
                $aws = new AwsEc2Service($awsCredentials->first());
                $fetched = $aws->getRegions();
                if ($fetched !== []) {
                    $awsRegions = $fetched;
                }
            } catch (\Throwable) {
                //
            }
        }

        $canCreateServer = $org ? $org->canCreateServer() : false;
        $billingUrl = $org ? route('billing.show', $org) : null;
        $setupScripts = config('setup_scripts.scripts', []);

        return view('livewire.servers.create', [
            'credentials' => $credentials,
            'regions' => $regions,
            'sizes' => $sizes,
            'hetznerCredentials' => $hetznerCredentials,
            'hetznerLocations' => $hetznerLocations,
            'hetznerSizes' => $hetznerSizes,
            'linodeCredentials' => $linodeCredentials,
            'linodeRegions' => $linodeRegions,
            'linodeTypes' => $linodeTypes,
            'vultrCredentials' => $vultrCredentials,
            'vultrRegions' => $vultrRegions,
            'vultrPlans' => $vultrPlans,
            'akamaiCredentials' => $akamaiCredentials,
            'akamaiRegions' => $akamaiRegions,
            'akamaiTypes' => $akamaiTypes,
            'scalewayCredentials' => $scalewayCredentials,
            'scalewayZones' => $scalewayZones,
            'scalewayTypes' => $scalewayTypes,
            'upcloudCredentials' => $upcloudCredentials,
            'upcloudZones' => $upcloudZones,
            'upcloudPlans' => $upcloudPlans,
            'equinixMetalCredentials' => $equinixMetalCredentials,
            'equinixMetalPlans' => $equinixMetalPlans,
            'equinixMetalMetros' => $equinixMetalMetros,
            'flyIoCredentials' => $flyIoCredentials,
            'flyIoRegions' => $flyIoRegions,
            'flyIoVmSizes' => $flyIoVmSizes,
            'awsCredentials' => $awsCredentials,
            'awsRegions' => $awsRegions,
            'awsInstanceTypes' => $awsInstanceTypes,
            'setupScripts' => $setupScripts,
            'canCreateServer' => $canCreateServer,
            'billingUrl' => $billingUrl,
        ]);
    }
}
