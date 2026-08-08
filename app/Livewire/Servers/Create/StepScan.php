<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Enums\ServerProvider;
use App\Jobs\RefreshServerInventoryJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\Servers\ProviderServerInventory;
use App\Services\Servers\SshReachabilityProbe;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Scan-and-import: the third mode on step 1. dply reads the machines that
 * already exist on a provider account, marks the ones it manages, and adopts
 * one of the rest — name, address, region and size come from the API, so the
 * only thing left to supply is SSH access.
 */
#[Layout('layouts.app')]
class StepScan extends Component
{
    use InteractsWithServerCreateDraft;

    public ServerCreateForm $form;

    public string $provider = '';

    public string $credentialId = '';

    /**
     * Normalised rows from the last scan — see ProviderServerInventory.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $found = [];

    public bool $scanned = false;

    public string $scanError = '';

    /** provider_id of the row the form is open for, or null when it's closed. */
    public ?string $adoptId = null;

    /**
     * What the open form will do on submit: 'import' creates the server,
     * 'repair' replaces the SSH credentials on one dply already has. Same
     * fields either way — a failed reachability check needs exactly the form
     * an import needs, pointed at an existing row.
     */
    public string $adoptMode = 'import';

    public string $adoptName = '';

    public string $adoptIp = '';

    public string $adoptSshUser = 'root';

    public string $adoptSshPort = '22';

    public string $adoptSshPrivateKey = '';

    /**
     * 'existing' (reuse a key dply already holds for another server in this
     * org), 'paste' (user supplies one), or 'generate' (dply mints one and the
     * user installs it). Existing is the common case: machines created from one
     * provider account usually authorize the same key.
     */
    public string $adoptKeySource = 'existing';

    /** Server whose stored key to reuse when adoptKeySource is 'existing'. */
    public string $adoptKeyServerId = '';

    /** Result of the last Test connection run: ['ok' => bool, 'message' => string]. */
    public ?array $probeResult = null;

    /**
     * Reachability verdicts for rows dply already manages, keyed by provider_id.
     * "Already in dply" only says a row exists — whether dply can actually log
     * in is the thing worth knowing, and a stale or missing key makes an
     * imported server useless without ever looking broken.
     *
     * @var array<string, array{ok: bool, message: string}>
     */
    public array $reachability = [];

    /** Surfaced after a 'generate' adopt so the key can be installed on the host. */
    public string $generatedPublicKey = '';

    public string $adoptedServerUrl = '';

    public string $adoptError = '';

    /** @var Collection<int, ProviderCredential>|null */
    private ?Collection $credentialsCache = null;

    /** @var Collection<int, ProviderCredential>|null */
    private ?Collection $allCredentialsCache = null;

    /** @var Collection<int, Server>|null */
    private ?Collection $reusableKeyServersCache = null;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        $draft = $this->currentDraft();
        $this->hydrateFormFromDraft($this->form, $draft);

        // Reached directly without picking import mode — send them to step 1
        // rather than showing a scan page the draft knows nothing about.
        if ($this->form->mode !== 'import') {
            return $this->redirect(route('servers.create', ['edit' => 1]), navigate: true);
        }

        // Default to the first provider *as the dropdown lists them*, so the
        // selected value and the visible first option always agree. Then drop
        // the account cache: it was built before $provider was known, so it
        // still holds accounts from every provider.
        $providers = $this->scannableProviders();
        if ($this->provider === '' && $providers !== []) {
            $this->provider = (string) array_key_first($providers);
            $this->credentialsCache = null;
            $this->allCredentialsCache = null;
        }
        $this->preselectSoleCredential();

        return null;
    }

    /**
     * Import mode branches off after step 1, so the draft never advances past
     * it — the remaining build steps have nothing to ask about.
     */
    protected function stepNumber(): int
    {
        return 1;
    }

    public function updatedProvider(): void
    {
        $this->credentialId = '';
        $this->resetScan();
        $this->credentialsCache = null;
        $this->allCredentialsCache = null;
        $this->preselectSoleCredential();
    }

    public function updatedCredentialId(): void
    {
        $this->resetScan();
    }

    public function scan(): void
    {
        $this->authorize('create', Server::class);
        $this->resetScan();

        $credential = $this->resolveCredential();
        if (! $credential) {
            $this->scanError = __('Pick an account to scan first.');

            return;
        }

        try {
            $this->found = app(ProviderServerInventory::class)->list($credential);
            $this->scanned = true;
        } catch (Throwable $e) {
            $this->scanError = __('The :provider API call failed: :message', [
                'provider' => $this->providerLabel($this->provider),
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function openAdopt(string $providerId): void
    {
        $row = $this->findRow($providerId);
        if ($row === null || ($row['imported'] ?? false)) {
            return;
        }

        $this->adoptError = '';
        $this->adoptId = $providerId;
        $this->adoptMode = 'import';
        // Step 1 never asked for a name in this mode — the machine already has
        // one. Unnamed hosts (Vultr lets you skip the label) fall back to
        // provider-and-id so the field is never empty.
        $this->adoptName = $this->sanitizeName((string) ($row['name'] ?? ''))
            ?: $this->sanitizeName($this->provider.'-'.$providerId);
        $this->adoptIp = (string) ($row['public_ipv4'] ?? '');
        $this->adoptSshUser = 'root';
        $this->adoptSshPort = '22';
        $this->adoptSshPrivateKey = '';
        $this->generatedPublicKey = '';
        $this->probeResult = null;

        $this->defaultKeySource();
    }

    /**
     * Open the same form against a server dply already manages, so a failed
     * reachability check has somewhere to go. Without it the red verdict is a
     * dead end: you can see dply cannot get in and do nothing about it here.
     */
    public function openRepair(string $providerId): void
    {
        $row = $this->findRow($providerId);
        $server = $this->matchedServer($row);
        if ($server === null) {
            return;
        }

        $this->adoptError = '';
        $this->adoptId = $providerId;
        $this->adoptMode = 'repair';
        $this->adoptName = (string) $server->name;
        // Prefer what dply has on file — that is what is being fixed — but fall
        // back to the provider's address when the row has none.
        $this->adoptIp = (string) ($server->ip_address ?: $row['public_ipv4'] ?? '');
        $this->adoptSshUser = (string) ($server->ssh_user ?: 'root');
        $this->adoptSshPort = (string) ($server->ssh_port ?: 22);
        $this->adoptSshPrivateKey = '';
        $this->generatedPublicKey = '';
        $this->probeResult = null;

        $this->defaultKeySource();
    }

    /** Reuse is the default whenever there is a key to reuse. */
    private function defaultKeySource(): void
    {
        $reusable = $this->reusableKeyServers();
        $this->adoptKeySource = $reusable->isNotEmpty() ? 'existing' : 'paste';
        $this->adoptKeyServerId = (string) ($reusable->first()->id ?? '');
    }

    /** The Server a scan row resolved to, scoped to the current org. */
    private function matchedServer(?array $row): ?Server
    {
        $serverId = $row['server_id'] ?? null;
        if ($serverId === null) {
            return null;
        }

        return Server::query()
            ->where('organization_id', auth()->user()?->currentOrganization()?->id)
            ->find($serverId);
    }

    public function updatedAdoptKeySource(): void
    {
        $this->probeResult = null;
    }

    public function updatedAdoptKeyServerId(): void
    {
        $this->probeResult = null;
    }

    /**
     * Try the chosen credentials against the host before anything is written.
     * Import succeeds either way; this is how you find out beforehand whether
     * dply will actually be able to reach the machine.
     */
    public function testConnection(): void
    {
        $this->probeResult = null;

        $key = $this->resolvePrivateKey();
        if ($key === null) {
            $this->probeResult = ['ok' => false, 'message' => $this->adoptKeySource === 'generate'
                ? __('There is nothing to test yet — the key is generated on import.')
                : __('Pick or paste a key first.')];

            return;
        }

        $this->probeResult = app(SshReachabilityProbe::class)->check(
            $this->adoptIp,
            (int) $this->adoptSshPort ?: 22,
            $this->adoptSshUser !== '' ? $this->adoptSshUser : 'root',
            $key,
        );
    }

    /**
     * Probe a server dply already manages, using the credentials dply holds for
     * it. Answers "can we still get in?" — which is the question the list was
     * silently not answering.
     */
    public function checkReachability(string $providerId): void
    {
        $row = $this->findRow($providerId);
        $serverId = $row['server_id'] ?? null;
        if ($serverId === null) {
            return;
        }

        $orgId = auth()->user()?->currentOrganization()?->id;
        $server = Server::query()
            ->where('organization_id', $orgId)
            ->find($serverId);

        if (! $server) {
            $this->reachability[$providerId] = ['ok' => false, 'message' => __('That server is no longer in dply.')];

            return;
        }

        $key = $server->ssh_private_key;
        if (! is_string($key) || trim($key) === '') {
            $this->reachability[$providerId] = ['ok' => false, 'message' => __('dply has no SSH key stored for this server.')];

            return;
        }

        $this->reachability[$providerId] = app(SshReachabilityProbe::class)->check(
            (string) ($server->ip_address ?: $row['public_ipv4'] ?? ''),
            (int) ($server->ssh_port ?: 22),
            (string) ($server->ssh_user ?: 'root'),
            $key,
        );
    }

    public function closeAdopt(): void
    {
        $this->adoptId = null;
        $this->adoptError = '';
        $this->resetValidation();
    }

    public function adopt(): mixed
    {
        $this->authorize('create', Server::class);

        if ($this->adoptId === null) {
            return null;
        }

        $credential = $this->resolveCredential();
        if (! $credential) {
            $this->adoptError = __('Lost the account — re-scan and try again.');

            return null;
        }

        $row = $this->findRow($this->adoptId);
        if ($row === null) {
            $this->adoptError = __('That server is no longer in the scan results — re-scan and try again.');

            return null;
        }

        $rules = [
            'adoptName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'adoptIp' => ['required', 'ip'],
            'adoptSshUser' => ['required', 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_-]*$/i'],
            'adoptSshPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'adoptKeySource' => ['required', 'in:existing,paste,generate'],
        ];
        if ($this->adoptKeySource === 'paste') {
            $rules['adoptSshPrivateKey'] = ['required', 'string', 'min:50'];
        }
        if ($this->adoptKeySource === 'existing') {
            $rules['adoptKeyServerId'] = [
                'required',
                Rule::in($this->reusableKeyServers()->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            ];
        }
        $this->validate($rules, attributes: [
            'adoptName' => __('server name'),
            'adoptIp' => __('IP address'),
            'adoptSshUser' => __('SSH user'),
            'adoptSshPort' => __('SSH port'),
            'adoptSshPrivateKey' => __('SSH private key'),
            'adoptKeyServerId' => __('server to borrow the key from'),
        ]);

        $publicKey = null;
        if ($this->adoptKeySource === 'generate') {
            try {
                [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
            } catch (Throwable $e) {
                $this->adoptError = $e->getMessage();

                return null;
            }
            $this->adoptSshPrivateKey = $private;
            $publicKey = $public;
        } elseif ($this->adoptKeySource === 'existing') {
            $borrowed = $this->resolvePrivateKey();
            if ($borrowed === null) {
                $this->adoptError = __('That server no longer has a stored key — pick another or paste one.');

                return null;
            }
            $this->adoptSshPrivateKey = $borrowed;
        }

        // Repair replaces the access details on a server dply already has; the
        // row, its history and everything hanging off it stay put.
        if ($this->adoptMode === 'repair') {
            $server = $this->matchedServer($row);
            if ($server === null) {
                $this->adoptError = __('That server is no longer in dply — re-scan and try again.');

                return null;
            }

            $server->forceFill([
                'name' => $this->adoptName,
                'ip_address' => $this->adoptIp,
                'ssh_port' => (int) $this->adoptSshPort,
                'ssh_user' => $this->adoptSshUser,
                'ssh_private_key' => $this->adoptSshPrivateKey,
                // Record the provider id if this row matched only by address,
                // so the next scan matches it the precise way.
                'provider_id' => $server->provider_id ?: (string) $this->adoptId,
            ])->save();

            $this->adoptId = null;
            $this->adoptedServerUrl = route('servers.overview', ['server' => $server]);

            if ($publicKey !== null) {
                $this->generatedPublicKey = $publicKey;

                return null;
            }

            // Re-probe straight away: the whole point was a red verdict, so
            // show whether it's green now rather than making them ask again.
            $this->checkReachability((string) $row['provider_id']);

            // Access is back, so refresh what dply knows is on the box.
            if (($this->reachability[(string) $row['provider_id']]['ok'] ?? false) === true) {
                RefreshServerInventoryJob::dispatch((string) $server->id);
            }

            return null;
        }

        $server = Server::create([
            'user_id' => $credential->user_id ?? auth()->id(),
            'organization_id' => $credential->organization_id,
            'provider_credential_id' => $credential->id,
            'name' => $this->adoptName,
            'provider' => $credential->provider,
            'provider_id' => (string) $this->adoptId,
            'ip_address' => $this->adoptIp,
            'ssh_port' => (int) $this->adoptSshPort,
            'ssh_user' => $this->adoptSshUser,
            'ssh_private_key' => $this->adoptSshPrivateKey,
            'status' => Server::STATUS_READY,
            // There is no setup to run — the machine is already built. Leaving
            // this pending makes the fleet advertise a provisioning journey
            // that will never start.
            'setup_status' => Server::SETUP_STATUS_DONE,
            'region' => $row['region'] ?? null,
            'size' => $row['size'] ?? null,
            'meta' => [
                'host_kind' => Server::HOST_KIND_VM,
                'adopted_from' => (string) $credential->provider,
                'adopted_provider_id' => (string) $this->adoptId,
                // An adopted machine is already running something. Flag it so
                // nothing treats it as a blank box to provision — dply learns
                // what is there instead of installing over it.
                'adopted' => true,
                'adopted_at' => now()->toIso8601String(),
            ],
        ]);

        // Read-only inventory probe: finds the webserver, PHP-FPM versions,
        // databases and nginx vhosts already on the box. It installs nothing —
        // anything missing is something to offer, not to assume.
        RefreshServerInventoryJob::dispatch((string) $server->id);

        // The draft has served its purpose — the machine exists, so there is
        // nothing left for the remaining wizard steps to build.
        $this->currentDraft()?->delete();

        if ($publicKey !== null) {
            // Keep the user here: dply cannot reach the box until this key is
            // in the host's authorized_keys, so show it before moving on.
            $this->generatedPublicKey = $publicKey;
            $this->adoptedServerUrl = route('servers.overview', ['server' => $server]);
            $this->adoptId = null;
            $this->markRowImported((string) $server->provider_id);

            return null;
        }

        session()->flash('status', __("Imported ':name' from :provider.", [
            'name' => $server->name,
            'provider' => $this->providerLabel((string) $credential->provider),
        ]));

        return $this->redirect(route('servers.overview', ['server' => $server]), navigate: true);
    }

    public function dismissGeneratedKey(): void
    {
        $this->generatedPublicKey = '';
        $this->adoptedServerUrl = '';
    }

    #[On('provider-credential-created')]
    public function refreshCredentials(?string $provider = null, mixed $credentialId = null): void
    {
        $this->credentialsCache = null;
        $this->allCredentialsCache = null;

        if (is_string($provider) && $provider !== '') {
            $this->provider = $provider;
            $this->credentialsCache = null;
            $this->allCredentialsCache = null;
        }

        if ($credentialId !== null && $credentialId !== '') {
            $this->credentialId = (string) $credentialId;

            return;
        }

        $this->preselectSoleCredential();
    }

    public function render(): View
    {
        $rows = collect($this->found);

        return view('livewire.servers.create.step-scan', [
            'credentials' => $this->availableCredentials(),
            'scannableProviders' => $this->scannableProviders(),
            'importableCount' => $rows->reject(fn (array $r): bool => (bool) ($r['imported'] ?? false))->count(),
            'importedCount' => $rows->filter(fn (array $r): bool => (bool) ($r['imported'] ?? false))->count(),
            'reusableKeyServers' => $this->reusableKeyServers(),
        ]);
    }

    /**
     * Servers in this org whose stored key we could reuse. Machines created
     * from one provider account usually share a key, so borrowing one is
     * normally the difference between a click and a copy-paste.
     *
     * @return Collection<int, Server>
     */
    private function reusableKeyServers(): Collection
    {
        if ($this->reusableKeyServersCache !== null) {
            return $this->reusableKeyServersCache;
        }

        $orgId = auth()->user()?->currentOrganization()?->id;
        if (! $orgId) {
            return $this->reusableKeyServersCache = collect();
        }

        // When repairing, the broken server's own key is not on the menu — it
        // is the thing that just failed.
        $exclude = $this->adoptMode === 'repair'
            ? $this->matchedServer($this->findRow((string) $this->adoptId))?->id
            : null;

        return $this->reusableKeyServersCache = Server::query()
            ->where('organization_id', $orgId)
            ->when($exclude !== null, fn ($q) => $q->whereKeyNot($exclude))
            ->whereNotNull('ssh_private_key')
            ->orderByRaw('CASE WHEN provider = ? THEN 0 ELSE 1 END', [$this->provider])
            ->orderBy('name')
            ->get();
    }

    /** The private key the current selection resolves to, or null. */
    private function resolvePrivateKey(): ?string
    {
        if ($this->adoptKeySource === 'paste') {
            return trim($this->adoptSshPrivateKey) !== '' ? $this->adoptSshPrivateKey : null;
        }

        if ($this->adoptKeySource === 'existing') {
            $server = $this->reusableKeyServers()->firstWhere('id', $this->adoptKeyServerId);
            $key = $server?->ssh_private_key;

            return is_string($key) && trim($key) !== '' ? $key : null;
        }

        return null;
    }

    /**
     * Providers this org holds credentials for that dply can enumerate.
     *
     * @return array<string, string>
     */
    private function scannableProviders(): array
    {
        return $this->allCredentials()
            ->pluck('provider')
            ->unique()
            ->filter(fn ($p): bool => app(ProviderServerInventory::class)->supports((string) $p))
            ->mapWithKeys(fn ($p): array => [(string) $p => $this->providerLabel((string) $p)])
            ->sort()
            ->all();
    }

    private function providerLabel(string $provider): string
    {
        return ServerProvider::tryFrom($provider)?->label() ?? ucfirst($provider);
    }

    /**
     * dply names allow letters, digits, dot, underscore and hyphen; providers
     * are looser (spaces, slashes). Coerce rather than hand the user a name
     * that fails validation the moment the adopt form opens.
     */
    private function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name)) ?? '';
        $clean = trim($clean, '-');

        return mb_substr($clean, 0, 64);
    }

    private function preselectSoleCredential(): void
    {
        $credentials = $this->availableCredentials();
        if ($this->credentialId === '' && $credentials->count() === 1) {
            $this->credentialId = (string) $credentials->first()->id;
        }
    }

    private function resetScan(): void
    {
        $this->found = [];
        $this->scanned = false;
        $this->scanError = '';
        $this->adoptId = null;
        $this->adoptError = '';
        $this->generatedPublicKey = '';
        $this->adoptedServerUrl = '';
        $this->probeResult = null;
        $this->reachability = [];
    }

    /** @return array<string, mixed>|null */
    private function findRow(string $providerId): ?array
    {
        foreach ($this->found as $row) {
            if ((string) ($row['provider_id'] ?? '') === $providerId) {
                return $row;
            }
        }

        return null;
    }

    /** Flip a row to imported in place, so the list reflects the adopt. */
    private function markRowImported(string $providerId): void
    {
        $this->found = array_map(
            fn (array $row): array => (string) ($row['provider_id'] ?? '') === $providerId
                ? array_merge($row, ['imported' => true])
                : $row,
            $this->found
        );
    }

    /** @return Collection<int, ProviderCredential> */
    private function allCredentials(): Collection
    {
        if ($this->allCredentialsCache !== null) {
            return $this->allCredentialsCache;
        }

        $orgId = auth()->user()?->currentOrganization()?->id;
        if (! $orgId) {
            return $this->allCredentialsCache = collect();
        }

        return $this->allCredentialsCache = ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, ProviderCredential> */
    private function availableCredentials(): Collection
    {
        if ($this->credentialsCache !== null) {
            return $this->credentialsCache;
        }

        $inventory = app(ProviderServerInventory::class);

        return $this->credentialsCache = $this->allCredentials()
            ->filter(fn (ProviderCredential $c): bool => $inventory->supports((string) $c->provider))
            ->when($this->provider !== '', fn (Collection $c): Collection => $c->where('provider', $this->provider))
            ->values();
    }

    private function resolveCredential(): ?ProviderCredential
    {
        if ($this->credentialId === '') {
            return null;
        }

        return $this->availableCredentials()->firstWhere('id', $this->credentialId);
    }
}
