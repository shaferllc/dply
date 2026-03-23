<?php

namespace App\Http\Controllers;

use App\Enums\ServerProvider;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\SshConnection;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(Request $request): View
    {
        $org = $request->user()->currentOrganization();
        if (! $org) {
            return view('servers.index', ['servers' => collect()]);
        }

        $servers = Server::query()
            ->where(function ($q) use ($request, $org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(fn ($q2) => $q2->whereNull('organization_id')->where('user_id', $request->user()->id));
            })
            ->latest()
            ->get();

        return view('servers.index', compact('servers'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Server::class);

        $org = $request->user()->currentOrganization();
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
                // ignore
            }
        }

        return view('servers.create', compact('credentials', 'regions', 'sizes'));
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')
                ->with('error', __('Please verify your email address before creating a server.'));
        }

        $this->authorize('create', Server::class);

        $org = $request->user()->currentOrganization();
        if (! $org) {
            return redirect()->route('servers.index')->with('error', 'Select or create an organization first.');
        }

        if (! $org->canCreateServer()) {
            return redirect()->back()->withInput()->with('error', 'Server limit reached for your plan. Upgrade to add more.');
        }

        $type = $request->input('type', 'digitalocean');

        if ($type === 'digitalocean') {
            $scriptKeys = array_keys(config('setup_scripts.scripts', []));
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->findOrFail($validated['provider_credential_id']);

            $setupScriptKey = ! empty($validated['setup_script_key'] ?? null) ? $validated['setup_script_key'] : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $request->user()->servers()->create([
                'organization_id' => $org->id,
                'name' => $validated['name'],
                'provider' => ServerProvider::DigitalOcean,
                'provider_credential_id' => $credential->id,
                'region' => $validated['region'],
                'size' => $validated['size'],
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionDigitalOceanDropletJob::dispatch($server);

            audit_log($org, $request->user(), 'server.created', $server);

            return redirect()->route('servers.show', $server)->with('success', 'Server is being created. This usually takes 1–2 minutes.');
        }

        if ($type === 'hetzner') {
            $scriptKeys = array_keys(config('setup_scripts.scripts', []));
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]);

            $credential = ProviderCredential::where('organization_id', $org->id)
                ->where('provider', 'hetzner')
                ->findOrFail($validated['provider_credential_id']);

            $setupScriptKey = ! empty($validated['setup_script_key'] ?? null) ? $validated['setup_script_key'] : null;
            $setupStatus = $setupScriptKey ? Server::SETUP_STATUS_PENDING : null;

            $server = $request->user()->servers()->create([
                'organization_id' => $org->id,
                'name' => $validated['name'],
                'provider' => ServerProvider::Hetzner,
                'provider_credential_id' => $credential->id,
                'region' => $validated['region'],
                'size' => $validated['size'],
                'setup_script_key' => $setupScriptKey,
                'setup_status' => $setupStatus,
                'status' => Server::STATUS_PENDING,
            ]);

            ProvisionHetznerServerJob::dispatch($server);

            audit_log($org, $request->user(), 'server.created', $server);

            return redirect()->route('servers.show', $server)->with('success', 'Server is being created. This usually takes 1–2 minutes.');
        }

        // Custom / existing server
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|string|max:45',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_user' => 'required|string|max:255',
            'ssh_private_key' => 'required|string',
        ]);

        $server = $request->user()->servers()->create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'provider' => ServerProvider::Custom,
            'ip_address' => $validated['ip_address'],
            'ssh_port' => $validated['ssh_port'] ?? 22,
            'ssh_user' => $validated['ssh_user'],
            'ssh_private_key' => $validated['ssh_private_key'],
            'status' => Server::STATUS_READY,
        ]);

        audit_log($org, $request->user(), 'server.created', $server);

        return redirect()->route('servers.show', $server)->with('success', 'Server added.');
    }

    public function show(Request $request, Server $server): View|RedirectResponse
    {
        $this->authorize('view', $server);

        return view('servers.show', compact('server'));
    }

    public function runCommand(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        $request->validate(['command' => 'required|string|max:1000']);

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($request->input('command'));

            return back()->with('command_output', $output);
        } catch (\Throwable $e) {
            return back()->with('command_error', $e->getMessage());
        }
    }

    public function deploy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        $command = $server->deploy_command;
        if (empty(trim((string) $command))) {
            return back()->with('error', 'Set a deploy command first. Use "Edit deploy command" below.');
        }

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($command);

            return back()->with('command_output', $output);
        } catch (\Throwable $e) {
            return back()->with('command_error', $e->getMessage());
        }
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $validated = $request->validate([
            'deploy_command' => 'nullable|string|max:2000',
        ]);

        $server->update(['deploy_command' => $validated['deploy_command'] ?? null]);

        return back()->with('success', 'Deploy command updated.');
    }

    public function checkHealth(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        if ($server->status === Server::STATUS_READY && ! empty($server->ip_address)) {
            CheckServerHealthJob::dispatch($server);
        }

        return back()->with('success', 'Health check has been queued. Status will update shortly.');
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $org = $server->organization;
        if ($org) {
            audit_log($org, $request->user(), 'server.deleted', $server, ['name' => $server->name], null);
        }

        if ($server->provider === ServerProvider::DigitalOcean && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $do = new DigitalOceanService($credential);
                    $do->destroyDroplet((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy DigitalOcean droplet on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Hetzner && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $hetzner = new HetznerService($credential);
                    $hetzner->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Hetzner instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (in_array($server->provider, [ServerProvider::Linode, ServerProvider::Akamai], true) && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $linode = new LinodeService($credential);
                    $linode->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Linode/Akamai instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Vultr && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $vultr = new VultrService($credential);
                    $vultr->destroyInstance($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Vultr instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Scaleway && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $scw = new ScalewayService($credential);
                    $scw->destroyServer($server->region, $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Scaleway instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::UpCloud && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $upcloud = new UpCloudService($credential);
                    $upcloud->destroyServer($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy UpCloud server on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::EquinixMetal && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $metal = new EquinixMetalService($credential);
                    $metal->destroyDevice($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Equinix Metal device on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::FlyIo && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            $appName = $server->meta['app_name'] ?? null;
            if ($credential && $appName) {
                try {
                    $fly = new FlyIoService($credential);
                    $fly->deleteMachine($appName, $server->provider_id);
                    $fly->deleteApp($appName);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Fly.io machine/app on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Aws && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $aws = new AwsEc2Service($credential, $server->region);
                    $aws->terminateInstances($server->provider_id);
                    $keyName = $server->meta['key_name'] ?? null;
                    if ($keyName) {
                        try {
                            $aws->deleteKeyPair($keyName);
                        } catch (\Throwable) {
                            //
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy AWS EC2 instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $server->delete();

        return redirect()->route('servers.index')->with('success', 'Server removed.');
    }
}
