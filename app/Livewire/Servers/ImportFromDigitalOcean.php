<?php

namespace App\Livewire\Servers;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class ImportFromDigitalOcean extends Component
{
    public string $credentialId = '';

    /**
     * Cached droplet list from the last successful scan. Each entry is the
     * raw DO API droplet array — we only read .id/.name/.networks/.region/.status.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $droplets = [];

    public string $scanError = '';

    public bool $scanning = false;

    public ?int $adoptDropletId = null;

    public string $adoptName = '';

    public string $adoptIp = '';

    public string $adoptSshUser = 'root';

    public string $adoptSshPort = '22';

    public string $adoptSshPrivateKey = '';

    /**
     * 'paste' (user supplies an existing key) or 'generate' (dply mints a
     * fresh ed25519 keypair on adopt). When 'generate', adoptSshPrivateKey
     * stays empty in the form until adopt() runs and fills it.
     */
    public string $adoptKeySource = 'paste';

    /**
     * Last-generated public key, surfaced post-adopt so the user can paste
     * it into the droplet's ~/.ssh/authorized_keys.
     */
    public string $generatedPublicKey = '';

    public string $adoptedServerUrl = '';

    public string $adoptError = '';

    /** @var Collection<int, ProviderCredential>|null */
    private ?Collection $credentialsCache = null;

    public function mount(): void
    {
        $credentials = $this->availableCredentials();
        if ($credentials->count() === 1) {
            $this->credentialId = (string) $credentials->first()->id;
        }
    }

    public function scan(): void
    {
        $this->scanError = '';
        $this->droplets = [];

        $credential = $this->resolveCredential();
        if (! $credential) {
            $this->scanError = 'Pick a DigitalOcean credential first.';

            return;
        }

        $this->scanning = true;
        try {
            $do = new DigitalOceanService($credential);
            $rows = $do->getDroplets();

            $known = Server::query()
                ->where('organization_id', $credential->organization_id)
                ->where('provider', ServerProvider::DigitalOcean->value)
                ->pluck('provider_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all();

            $this->droplets = collect($rows)
                ->map(fn (array $d) => array_merge($d, [
                    '_already_imported' => in_array((string) ($d['id'] ?? ''), $known, true),
                    '_public_ipv4' => $this->extractPublicIpv4($d),
                ]))
                ->all();
        } catch (Throwable $e) {
            $this->scanError = 'DigitalOcean API call failed: '.$e->getMessage();
        } finally {
            $this->scanning = false;
        }
    }

    public function openAdoptModal(int $dropletId): void
    {
        $this->adoptError = '';
        $droplet = collect($this->droplets)->firstWhere('id', $dropletId);
        if (! $droplet) {
            return;
        }

        $this->adoptDropletId = $dropletId;
        $this->adoptName = (string) ($droplet['name'] ?? Str::slug('do-'.$dropletId));
        $this->adoptIp = (string) ($droplet['_public_ipv4'] ?? '');
        $this->adoptSshUser = 'root';
        $this->adoptSshPort = '22';
        $this->adoptSshPrivateKey = '';
        $this->adoptKeySource = 'paste';
        $this->generatedPublicKey = '';
    }

    public function closeAdoptModal(): void
    {
        $this->adoptDropletId = null;
        $this->adoptError = '';
    }

    public function adopt(): void
    {
        if ($this->adoptDropletId === null) {
            return;
        }

        $credential = $this->resolveCredential();
        if (! $credential) {
            $this->adoptError = 'Lost the credential — re-scan and try again.';

            return;
        }

        $rules = [
            'adoptName' => ['required', 'string', 'max:120'],
            'adoptIp' => ['required', 'ip'],
            'adoptSshUser' => ['required', 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_-]*$/i'],
            'adoptSshPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'adoptKeySource' => ['required', 'in:paste,generate'],
        ];

        if ($this->adoptKeySource === 'paste') {
            $rules['adoptSshPrivateKey'] = ['required', 'string', 'min:50'];
        }

        $this->validate($rules);

        $publicKey = null;
        if ($this->adoptKeySource === 'generate') {
            try {
                [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
            } catch (Throwable $e) {
                $this->adoptError = $e->getMessage();

                return;
            }
            $this->adoptSshPrivateKey = $private;
            $publicKey = $public;
        }

        $droplet = collect($this->droplets)->firstWhere('id', $this->adoptDropletId);
        $region = $droplet ? (string) ($droplet['region']['slug'] ?? '') : '';
        $size = $droplet ? (string) ($droplet['size_slug'] ?? '') : '';

        $server = Server::create([
            'user_id' => $credential->user_id ?? auth()->id(),
            'organization_id' => $credential->organization_id,
            'provider_credential_id' => $credential->id,
            'name' => $this->adoptName,
            'provider' => ServerProvider::DigitalOcean,
            'provider_id' => (string) $this->adoptDropletId,
            'ip_address' => $this->adoptIp,
            'ssh_port' => (int) $this->adoptSshPort,
            'ssh_user' => $this->adoptSshUser,
            'ssh_private_key' => $this->adoptSshPrivateKey,
            'status' => Server::STATUS_READY,
            'region' => $region !== '' ? $region : null,
            'size' => $size !== '' ? $size : null,
            'meta' => [
                'host_kind' => Server::HOST_KIND_VM,
                'adopted_from' => 'digitalocean',
                'droplet_id' => $this->adoptDropletId,
            ],
        ]);

        if ($publicKey !== null) {
            // Keep the modal open with a "next steps" panel: the user must
            // add this public key to /root/.ssh/authorized_keys on the
            // droplet before dply can SSH in. We stash the server URL so
            // they can navigate when ready.
            $this->generatedPublicKey = $publicKey;
            $this->adoptedServerUrl = route('servers.overview', ['server' => $server]);
            $this->adoptDropletId = null; // closes the form, opens the result panel

            return;
        }

        session()->flash('status', "Imported droplet '{$server->name}' (id {$server->id}). Configure SSH/runtime as needed.");

        $this->redirectRoute('servers.overview', ['server' => $server], navigate: true);
    }

    public function dismissGeneratedKey(): void
    {
        $this->generatedPublicKey = '';
        $this->adoptedServerUrl = '';
    }

    #[On('provider-credential-created')]
    public function refreshCredentials(?string $provider = null, mixed $credentialId = null): void
    {
        if ($provider !== null && $provider !== 'digitalocean') {
            return;
        }

        if ($credentialId !== null && $credentialId !== '') {
            $this->credentialId = (string) $credentialId;

            return;
        }

        $this->credentialsCache = null;

        $credentials = $this->availableCredentials();
        if ($credentials->count() === 1) {
            $this->credentialId = (string) $credentials->first()->id;
        }
    }

    public function render(): View
    {
        $dropletCollection = collect($this->droplets);

        return view('livewire.servers.import-from-digital-ocean', [
            'credentials' => $this->availableCredentials(),
            'dropletStats' => [
                'total' => $dropletCollection->count(),
                'available' => $dropletCollection->where(fn (array $d): bool => ! ($d['_already_imported'] ?? false))->count(),
                'imported' => $dropletCollection->where(fn (array $d): bool => (bool) ($d['_already_imported'] ?? false))->count(),
            ],
        ]);
    }

    /**
     * @return Collection<int, ProviderCredential>
     */
    private function availableCredentials(): Collection
    {
        if ($this->credentialsCache !== null) {
            return $this->credentialsCache;
        }

        $user = auth()->user();
        if (! $user) {
            return $this->credentialsCache = collect();
        }

        $orgId = $user->currentOrganization()?->id;
        if (! $orgId) {
            return $this->credentialsCache = collect();
        }

        return $this->credentialsCache = ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->where('provider', 'digitalocean')
            ->orderBy('name')
            ->get();
    }

    private function resolveCredential(): ?ProviderCredential
    {
        if ($this->credentialId === '') {
            return null;
        }

        return $this->availableCredentials()->firstWhere('id', $this->credentialId);
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private function extractPublicIpv4(array $droplet): ?string
    {
        $networks = $droplet['networks']['v4'] ?? [];
        if (! is_array($networks)) {
            return null;
        }

        foreach ($networks as $net) {
            if (is_array($net) && ($net['type'] ?? null) === 'public' && ! empty($net['ip_address'])) {
                return (string) $net['ip_address'];
            }
        }

        return null;
    }
}
