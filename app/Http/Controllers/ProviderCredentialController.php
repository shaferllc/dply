<?php

namespace App\Http\Controllers;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\VultrService;
use App\Support\ServerProviderGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderCredentialController extends Controller
{
    public function index(Request $request): View
    {
        $org = $request->user()->currentOrganization();
        $credentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->latest()->get()
            : $request->user()->providerCredentials()->whereNull('organization_id')->latest()->get();

        return view('credentials.index', compact('credentials'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ProviderCredential::class);

        $org = $request->user()->currentOrganization();
        if (! $org) {
            return redirect()->route('credentials.index')->with('error', 'Select or create an organization first.');
        }

        $allowedProviders = array_values(array_filter(
            ServerProvider::valuesForCredentials(),
            static fn (string $value) => ServerProviderGate::enabled($value)
        ));

        if ($allowedProviders === []) {
            return redirect()->route('credentials.index')
                ->with('error', __('No server providers are enabled for this application.'));
        }

        $validated = $request->validate([
            'provider' => ['required', Rule::in($allowedProviders)],
            'name' => 'nullable|string|max:255',
            'api_token' => 'required|string',
        ]);

        $provider = ServerProvider::from($validated['provider']);
        $defaultName = $provider->label();
        $credential = $request->user()->providerCredentials()->create([
            'organization_id' => $org->id,
            'provider' => $validated['provider'],
            'name' => $validated['name'] ?: $defaultName,
            'credentials' => ['api_token' => $validated['api_token']],
        ]);

        try {
            if ($validated['provider'] === 'digitalocean') {
                $do = new DigitalOceanService($credential);
                $do->getDroplets();
            } elseif ($validated['provider'] === 'hetzner') {
                $hetzner = new HetznerService($credential);
                $hetzner->validateToken();
            } elseif ($validated['provider'] === 'linode' || $validated['provider'] === 'akamai') {
                $linode = new LinodeService($credential);
                $linode->validateToken();
            } elseif ($validated['provider'] === 'vultr') {
                $vultr = new VultrService($credential);
                $vultr->validateToken();
            } elseif ($validated['provider'] === 'cloudflare') {
                (new CloudflareDnsService($credential))->verifyToken();
            } else {
                throw new \InvalidArgumentException('Unknown provider: '.$validated['provider']);
            }
        } catch (\Throwable $e) {
            $credential->delete();

            return back()->withInput()->withErrors(['api_token' => 'Invalid token or API error: '.$e->getMessage()]);
        }

        return redirect()->route('credentials.index')->with('success', 'Provider connected.');
    }

    public function destroy(Request $request, ProviderCredential $providerCredential): RedirectResponse
    {
        $this->authorize('delete', $providerCredential);

        $providerCredential->delete();

        return redirect()->route('credentials.index')->with('success', 'Credential removed.');
    }
}
