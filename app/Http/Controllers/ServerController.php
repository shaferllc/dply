<?php

namespace App\Http\Controllers;

use App\Actions\Servers\DeleteServerAction;
use App\Enums\ServerProvider;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\SshConnection;
use App\Support\ServerProviderGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (! ServerProviderGate::enabled($type)) {
            return redirect()->back()->withInput()->with('error', __('This server provider is not available yet.'));
        }

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

    public function show(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        return redirect()->route('servers.sites', $server);
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

    public function destroy(Request $request, Server $server, DeleteServerAction $deleteServer): RedirectResponse
    {
        $this->authorize('delete', $server);
        $deleteServer->execute(
            $server,
            $request->user(),
            ['via' => 'http_delete'],
            __('Removed via the servers API.'),
        );

        return redirect()->route('servers.index')->with('success', 'Server removed.');
    }
}
