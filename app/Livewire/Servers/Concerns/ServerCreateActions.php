<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Actions\Servers\BuildProviderCredentialHealth;
use App\Actions\Servers\BuildServerCreatePreflight;
use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;
use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\RecommendServerCreateSizes;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\SshConnectionFactory;
use App\Support\ServerProviderGate;
use App\Support\Servers\CacheEngineAvailability;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Catalog/preflight/connection helpers shared by the four create-server wizard step
 * components (Steps 2/3/4). Step 1 doesn't need any of this.
 *
 * Each step that uses this trait must declare a public ServerCreateForm $form;
 * the trait reads/writes through $this->form like the original Create.php did.
 */
trait ServerCreateActions
{
    public string $active_provider = 'digitalocean';

    public string $customConnectionTestState = 'idle';

    public string $customConnectionTestMessage = '';

    public ?string $customConnectionTestedAt = null;

    public ?string $customConnectionTestSignature = null;

    /** @var array<string, mixed>|null */
    protected ?array $memoServerCreateCatalog = null;

    protected ?string $memoServerCreateCatalogKey = null;

    /** @var list<array<string, mixed>>|null */
    protected ?array $memoListServerProviderCards = null;

    /** @var array<int, mixed>|null */
    protected ?array $memoCredentialProviderNav = null;

    public function updatedActiveProvider(mixed $value): void
    {
        $ids = CredentialsIndex::credentialProviderIds();
        if (! is_string($value) || ! in_array($value, $ids, true)) {
            $this->active_provider = $ids[0] ?? 'digitalocean';
        }
    }

    public function updatedFormProviderCredentialId(): void
    {
        if ($this->form->type !== 'custom') {
            $this->syncProvisionPreferenceFields();
        }
    }

    public function updatedFormRegion(): void
    {
        // Region-scoped catalogs (DO, Scaleway) must re-resolve with the
        // new region so the size dropdown stops offering plans that don't
        // exist in that DC. We also blank the current size pick — if it
        // isn't available in the new region the next render would silently
        // leave it stale and provisioning would fail server-side.
        if (in_array($this->form->type, ['scaleway', 'digitalocean'], true)) {
            $this->form->size = '';
            $this->memoServerCreateCatalog = null;
            $this->memoServerCreateCatalogKey = null;
        }
    }

    public function updatedFormIpAddress(): void
    {
        $this->resetCustomConnectionTestState();
    }

    public function updatedFormSshPort(): void
    {
        $this->resetCustomConnectionTestState();
    }

    public function updatedFormSshUser(): void
    {
        $this->resetCustomConnectionTestState();
    }

    public function updatedFormSshPrivateKey(): void
    {
        $this->resetCustomConnectionTestState();
    }

    public function updatedFormInstallProfile(): void
    {
        $this->applyInstallProfile();
    }

    public function afterProviderCredentialStored(string $provider): void
    {
        $this->active_provider = $provider;

        if ($this->form->mode === 'provider') {
            $this->applyCloudDefaults($provider);
        }
    }

    public function chooseProvider(string $provider): void
    {
        if (! ServerProviderGate::enabled($provider)) {
            return;
        }
        $this->form->mode = 'provider';
        $this->active_provider = $provider;
        $this->applyCloudDefaults($provider);
    }

    public function testCustomConnection(): void
    {
        $this->resetErrorBag([
            'form.ip_address',
            'form.ssh_port',
            'form.ssh_user',
            'form.ssh_private_key',
        ]);

        try {
            Validator::make(
                [
                    'ip_address' => $this->form->ip_address,
                    'ssh_port' => $this->form->ssh_port,
                    'ssh_user' => $this->form->ssh_user,
                    'ssh_private_key' => $this->form->ssh_private_key,
                ],
                [
                    'ip_address' => 'required|string|max:255',
                    'ssh_port' => 'nullable|integer|min:1|max:65535',
                    'ssh_user' => 'required|string|max:255',
                    'ssh_private_key' => 'required|string',
                ]
            )->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $this->addError('form.'.$field, $msg);
                }
            }
            $this->customConnectionTestState = 'error';
            $this->customConnectionTestMessage = __('Fill in the required SSH fields before testing the connection.');

            return;
        }

        $server = new Server([
            'name' => $this->form->name !== '' ? $this->form->name : 'custom-server-test',
            'ip_address' => $this->form->ip_address,
            'ssh_port' => (int) ($this->form->ssh_port !== '' ? $this->form->ssh_port : '22'),
            'ssh_user' => $this->form->ssh_user,
            'ssh_private_key' => $this->form->ssh_private_key,
        ]);

        try {
            $ssh = app(SshConnectionFactory::class)->forServer($server);
            if (! $ssh->connect(8)) {
                throw new \RuntimeException('SSH authentication failed.');
            }

            $user = trim($ssh->exec('whoami', 8));

            $this->customConnectionTestState = 'success';
            $this->customConnectionTestMessage = $user !== ''
                ? __('SSH connection verified as :user.', ['user' => $user])
                : __('SSH connection verified successfully.');
            $this->customConnectionTestedAt = now()->toIso8601String();
            $this->customConnectionTestSignature = $this->currentCustomConnectionSignature();
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            $this->customConnectionTestState = str_contains($message, 'auth') || str_contains($message, 'permission')
                ? 'error'
                : 'warning';
            $this->customConnectionTestMessage = __('SSH test failed: :message', ['message' => $e->getMessage()]);
            $this->customConnectionTestedAt = now()->toIso8601String();
            $this->customConnectionTestSignature = $this->currentCustomConnectionSignature();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveServerCreateCatalog(Organization $org, ?string $selectedRegionOverride = null): array
    {
        $selectedRegion = $selectedRegionOverride ?? $this->form->region;
        // Sizes are region-scoped on every provider that publishes per-region
        // availability — Scaleway's catalog is region-specific, and DO's
        // /sizes returns a regions array per plan. Without region in the
        // memo key, switching region reuses the previous region's sizes.
        $memoSegment = in_array($this->form->type, ['scaleway', 'digitalocean'], true) ? $selectedRegion : '';
        $memoKey = implode('|', [(string) $org->getKey(), $this->form->type, $this->form->provider_credential_id, $memoSegment]);

        if ($this->memoServerCreateCatalog !== null && $this->memoServerCreateCatalogKey === $memoKey) {
            return $this->memoServerCreateCatalog;
        }

        $catalog = ResolveServerCreateCatalog::run(
            $org,
            $this->form->type,
            $this->form->provider_credential_id,
            $selectedRegion,
        );

        $this->memoServerCreateCatalogKey = $memoKey;
        $this->memoServerCreateCatalog = $catalog;

        return $catalog;
    }

    /**
     * @return list<array{id: string, label: string, linked: bool, server_count: int, site_count: int}>
     */
    protected function listServerProviderCards(): array
    {
        if ($this->memoListServerProviderCards !== null) {
            return $this->memoListServerProviderCards;
        }

        $org = auth()->user()?->currentOrganization();

        return $this->memoListServerProviderCards = ListServerProviderCards::run($org);
    }

    /**
     * @return array<int, mixed>
     */
    protected function memoCredentialProviderNav(): array
    {
        return $this->memoCredentialProviderNav ??= CredentialsIndex::credentialProviderNav();
    }

    /**
     * @param  list<array{id: string, label: string, linked: bool}>  $cards
     * @return list<array{id: string, label: string, linked: bool, server_count: int, site_count: int}>
     */
    protected function provisionProviderCardsFromList(array $cards): array
    {
        return array_values(array_filter(
            $cards,
            fn (array $card): bool => in_array($card['id'], [
                'digitalocean', 'hetzner', 'vultr', 'linode', 'akamai',
                'scaleway', 'upcloud', 'equinix_metal', 'fly_io', 'aws', 'gcp', 'azure', 'oracle',
            ], true)
        ));
    }

    protected function defaultProvisionProvider(): string
    {
        foreach ($this->provisionProviderCardsFromList($this->listServerProviderCards()) as $card) {
            if (($card['linked'] ?? false) === true) {
                return $card['id'];
            }
        }

        return 'digitalocean';
    }

    protected function syncProvisionPreferenceFields(?Collection $credentials = null): void
    {
        if (in_array($this->form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_kubernetes', 'aws_lambda'], true)) {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        $hasLinkedCredential = $org
            ? ($credentials ?? GetProviderCredentialsForServerType::run($org, $this->form->type))->isNotEmpty()
            : false;

        $opts = FilterServerProvisionOptionsForCreateForm::run(
            $this->form->type,
            $hasLinkedCredential,
            $this->form->server_role,
        );

        $map = [
            'server_role' => 'server_roles',
            'cache_service' => 'cache_services',
            'webserver' => 'webservers',
            'php_version' => 'php_versions',
            'database' => 'databases',
        ];

        foreach ($map as $prop => $configKey) {
            $ids = collect($opts[$configKey] ?? [])->pluck('id')->filter()->values()->all();
            if ($ids === []) {
                continue;
            }
            if (! in_array($this->form->{$prop}, $ids, true)) {
                $this->form->{$prop} = $ids[0];
            }
        }

        $this->ensureInstallProfileMatchesCurrentSelections();
    }

    protected function applyCloudDefaults(?string $preferredType = null): void
    {
        $org = auth()->user()?->currentOrganization();
        if (! $org) {
            return;
        }

        $type = $preferredType;
        if ($type === null || ! ServerProviderGate::enabled($type)) {
            $type = collect($this->listServerProviderCards())
                ->first(fn (array $card): bool => $card['id'] !== 'custom' && ($card['linked'] ?? false))['id'] ?? null;
        }

        if (! is_string($type) || $type === '') {
            return;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $type);
        $credential = $credentials->first();
        if (! $credential) {
            // Allow the type to be selected even without a credential — the UI will prompt.
            $this->form->type = $type;
            $this->active_provider = $type;

            return;
        }

        $this->form->type = $type;
        $this->form->provider_credential_id = (string) $credential->id;
        $this->active_provider = $type;
        $this->applyInstallProfile();
        $this->syncProvisionPreferenceFields($credentials);

        $catalog = $this->resolveServerCreateCatalog($org, '');

        $this->form->region = $this->preferredRegionValue($catalog['regions'] ?? []);

        if ($this->form->type === 'scaleway' && $this->form->region !== '') {
            $catalog = $this->resolveServerCreateCatalog($org);
        }

        $this->form->size = $this->recommendedSizeValue($catalog['sizes'] ?? [], $this->form->server_role);
    }

    /**
     * @param  array<int, array<string, mixed>>  $regions
     */
    protected function preferredRegionValue(array $regions): string
    {
        if ($regions === []) {
            return '';
        }

        // US west coast first, regardless of detected country — matches the product's
        // default geographic preference. After that, fall through to country-based tokens.
        $westCoastFirst = ['sfo', 'sea', 'lax', 'pdx', 'us-west', 'uswest', 'oregon', 'california', 'san-jose', 'silicon'];

        $countryCode = strtoupper((string) (auth()->user()?->country_code ?? ''));
        $countryTokens = match ($countryCode) {
            'US', 'CA' => ['sfo', 'sea', 'lax', 'pdx', 'nyc', 'tor', 'atl', 'chi', 'iad'],
            'GB', 'IE' => ['lon', 'lhr', 'man', 'ams', 'fra'],
            'DE', 'AT', 'CH' => ['fra', 'fsn', 'nbg', 'ams', 'par'],
            'NL', 'BE', 'LU' => ['ams', 'fra', 'par', 'lon'],
            'FR' => ['par', 'fra', 'ams', 'mad'],
            'ES', 'PT' => ['mad', 'par', 'fra', 'ams'],
            'IT' => ['mil', 'fra', 'ams', 'par'],
            'SE', 'NO', 'DK', 'FI' => ['sto', 'hel', 'fra', 'ams'],
            'PL', 'CZ', 'HU', 'RO' => ['waw', 'fra', 'ams'],
            'SG', 'MY', 'ID', 'TH', 'VN', 'PH' => ['sgp', 'sin', 'hkg', 'tok'],
            'JP', 'KR', 'TW', 'HK' => ['tok', 'osa', 'hkg', 'sgp'],
            'IN' => ['blr', 'bom', 'sin', 'sgp'],
            'AU', 'NZ' => ['syd', 'mel', 'sin', 'sgp'],
            'BR', 'AR', 'CL', 'CO', 'MX', 'PE' => ['sao', 'gru', 'mex', 'mia', 'nyc'],
            'ZA', 'NG', 'KE', 'EG', 'MA' => ['jnb', 'fra', 'ams', 'lon'],
            default => [],
        };

        $preferredTokens = array_values(array_unique(array_merge($westCoastFirst, $countryTokens)));

        foreach ($preferredTokens as $token) {
            foreach ($regions as $region) {
                $haystack = strtolower(($region['value'] ?? '').' '.($region['label'] ?? ''));
                if (str_contains($haystack, $token)) {
                    return (string) ($region['value'] ?? '');
                }
            }
        }

        return (string) ($regions[0]['value'] ?? '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sizes
     */
    protected function smallestSizeValue(array $sizes): string
    {
        if ($sizes === []) {
            return '';
        }

        usort($sizes, function (array $a, array $b): int {
            return [$this->sizeWeight($a), strtolower((string) ($a['value'] ?? ''))]
                <=>
                [$this->sizeWeight($b), strtolower((string) ($b['value'] ?? ''))];
        });

        return (string) ($sizes[0]['value'] ?? '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sizes
     */
    protected function recommendedSizeValue(array $sizes, string $serverRole): string
    {
        // Default to the cheapest plan available — most users start with the lowest-cost
        // droplet and resize later. Recommendations stay surfaced as badges on the picker.
        $cheapest = $this->cheapestSizeValue($sizes);
        if ($cheapest !== '') {
            return $cheapest;
        }

        return $this->smallestSizeValue($sizes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sizes
     */
    protected function cheapestSizeValue(array $sizes): string
    {
        if ($sizes === []) {
            return '';
        }

        usort($sizes, function (array $a, array $b): int {
            $priceA = $this->extractMonthlyPrice($a);
            $priceB = $this->extractMonthlyPrice($b);

            // Plans with no parseable price sink to the bottom; tie-break by memory weight
            // so we don't accidentally pick a "Custom" plan over a real $4/mo entry.
            if ($priceA === null && $priceB === null) {
                return $this->sizeWeight($a) <=> $this->sizeWeight($b);
            }
            if ($priceA === null) {
                return 1;
            }
            if ($priceB === null) {
                return -1;
            }

            return $priceA <=> $priceB;
        });

        return (string) ($sizes[0]['value'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $size
     */
    protected function extractMonthlyPrice(array $size): ?float
    {
        $label = strtolower((string) ($size['label'] ?? ''));
        if (preg_match('/\$(\d+(?:\.\d+)?)\s*\/?\s*mo/', $label, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $size
     * @return array<int, int|float>
     */
    protected function sizeWeight(array $size): array
    {
        $label = strtolower((string) ($size['label'] ?? ''));
        $memoryMb = 0;
        if (preg_match('/(\d+(?:\.\d+)?)\s*gb\b/', $label, $matches) === 1) {
            $memoryMb = (int) round(((float) $matches[1]) * 1024);
        } elseif (preg_match('/(\d+)\s*mb\b/', $label, $matches) === 1) {
            $memoryMb = (int) $matches[1];
        }

        $cpuCount = 0;
        if (preg_match('/(\d+)\s*(?:vcpu|cpu)\b/', $label, $matches) === 1) {
            $cpuCount = (int) $matches[1];
        }

        $diskGb = 0;
        if (preg_match('/(\d+)\s*gb\s*disk\b/', $label, $matches) === 1) {
            $diskGb = (int) $matches[1];
        }

        $monthly = 0.0;
        if (preg_match('/\$(\d+(?:\.\d+)?)\/mo/', $label, $matches) === 1) {
            $monthly = (float) $matches[1];
        }

        return [$memoryMb, $cpuCount, $diskGb, $monthly];
    }

    /**
     * @return array{
     *     catalog: array<string, mixed>,
     *     provisionOptions: array<string, mixed>,
     *     preflight: array<string, mixed>,
     *     canCreateServer: bool,
     *     hasAnyProviderCredentials: bool,
     *     hasLinkedCredential: bool
     * }
     */
    protected function buildPreflightContext(?Organization $org): array
    {
        $catalog = $org
            ? $this->resolveServerCreateCatalog($org)
            : [
                'credentials' => collect(),
                'regions' => [],
                'sizes' => [],
                'region_label' => __('Region'),
                'size_label' => __('Plan / size'),
            ];

        $canCreateServer = $org ? $org->canCreateServer() : false;
        $userSshKeys = auth()->user()?->sshKeys();
        $hasUserSshKeys = $userSshKeys?->exists() ?? false;
        $hasProvisionableUserSshKeys = $userSshKeys?->where('provision_on_new_servers', true)->exists() ?? false;
        $hasAnyProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;
        // resolveServerCreateCatalog already ran GetProviderCredentialsForServerType
        // for this (org, type) — reuse its result instead of repeating the query.
        $hasLinkedCredential = $catalog['credentials'] instanceof Collection
            ? $catalog['credentials']->isNotEmpty()
            : false;
        $provisionOptions = FilterServerProvisionOptionsForCreateForm::run(
            $this->form->type,
            $hasLinkedCredential,
            $this->form->server_role,
        );
        $sizeRecommendations = RecommendServerCreateSizes::run($this->form->server_role, $catalog['sizes'] ?? []);
        if (($catalog['sizes'] ?? []) !== []) {
            $catalog['sizes'] = array_map(function (array $size) use ($sizeRecommendations): array {
                $value = (string) ($size['value'] ?? '');
                if ($value !== '' && isset($sizeRecommendations[$value])) {
                    $size['recommendation'] = $sizeRecommendations[$value];
                }

                return $size;
            }, $catalog['sizes']);
        }
        $selectedCredential = $catalog['credentials'] instanceof Collection
            ? $catalog['credentials']->firstWhere('id', $this->form->provider_credential_id)
            : null;
        $providerHealth = $this->form->type !== 'custom' && $this->form->provider_credential_id !== '' && $selectedCredential instanceof ProviderCredential
            ? BuildProviderCredentialHealth::run($this->form->type, $selectedCredential)
            : null;

        return [
            'catalog' => $catalog,
            'provisionOptions' => $provisionOptions,
            'preflight' => BuildServerCreatePreflight::run(
                $this->form,
                $catalog,
                $provisionOptions,
                $canCreateServer,
                $hasUserSshKeys,
                $hasProvisionableUserSshKeys,
                $hasAnyProviderCredentials,
                $hasLinkedCredential,
                $providerHealth,
                [
                    'state' => $this->customConnectionTestState,
                    'message' => $this->customConnectionTestMessage,
                    'tested_at' => $this->customConnectionTestedAt,
                    'matches_current_form' => $this->customConnectionTestSignature !== null
                        && $this->customConnectionTestSignature === $this->currentCustomConnectionSignature(),
                ],
                $sizeRecommendations,
                // Components that mix in this trait expose stepNumber(); we use
                // it to gate "blocking" severity on checks that only matter at
                // submit (e.g. K8s cluster pick — empty on StepWhere isn't a
                // bug, the user hasn't reached the picker yet).
                method_exists($this, 'stepNumber') ? (int) $this->stepNumber() : null,
            ),
            'canCreateServer' => $canCreateServer,
            'hasAnyProviderCredentials' => $hasAnyProviderCredentials,
            'hasLinkedCredential' => $hasLinkedCredential,
        ];
    }

    protected function applyInstallProfile(): void
    {
        if (in_array($this->form->type, ['digitalocean_functions', 'digitalocean_kubernetes', 'aws_kubernetes', 'aws_lambda'], true)) {
            return;
        }

        $profile = collect(config('server_provision_options.install_profiles', []))
            ->firstWhere('id', $this->form->install_profile);

        if (! is_array($profile)) {
            return;
        }

        foreach (['server_role', 'cache_service', 'webserver', 'php_version', 'database', 'setup_script_key'] as $field) {
            if (array_key_exists($field, $profile) && is_string($profile[$field])) {
                $this->form->{$field} = $profile[$field];
            }
        }

        $this->syncProvisionPreferenceFields();
    }

    protected function ensureInstallProfileMatchesCurrentSelections(): void
    {
        if ($this->isDedicatedCacheServerPurposeRole()) {
            $this->normalizeDedicatedCacheServerForm();

            return;
        }

        $matching = collect(config('server_provision_options.install_profiles', []))->first(function (array $profile): bool {
            return ($profile['server_role'] ?? null) === $this->form->server_role
                && ($profile['cache_service'] ?? null) === $this->form->cache_service
                && ($profile['webserver'] ?? null) === $this->form->webserver
                && ($profile['php_version'] ?? null) === $this->form->php_version
                && ($profile['database'] ?? null) === $this->form->database;
        });

        $this->form->install_profile = is_array($matching)
            ? (string) $matching['id']
            : '';
    }

    protected function installProfileIdForServerRole(string $serverRole): ?string
    {
        return match ($serverRole) {
            'redis', 'valkey' => 'redis_server',
            'database' => 'database_node',
            'worker' => 'queue_worker',
            'plain' => 'static_app_host',
            'application' => 'laravel_app',
            default => null,
        };
    }

    protected function isDedicatedCacheServerPurposeRole(?string $role = null): bool
    {
        return in_array($role ?? $this->form->server_role, ['redis', 'valkey'], true);
    }

    protected function normalizeDedicatedCacheServerForm(): void
    {
        if (! $this->isDedicatedCacheServerPurposeRole()) {
            return;
        }

        if ($this->form->server_role === 'valkey' && ($this->form->cache_service === 'none' || $this->form->cache_service === '')) {
            $this->form->cache_service = 'valkey';
        }

        $this->form->server_role = 'redis';

        if ($this->form->cache_service === 'none' || $this->form->cache_service === '') {
            $this->form->cache_service = 'redis';
        }

        if (! DedicatedCacheServerProvisionConfig::engineSupportsRemoteAccess($this->form->cache_service)) {
            $this->form->cache_remote_access = false;
            $this->form->cache_allowed_from = '';
        }

        if (! ServerCacheService::engineSupportsAuth($this->form->cache_service)) {
            $this->form->cache_require_password = false;
            $this->form->cache_password = '';
        }

        $this->form->install_profile = 'redis_server';
    }

    /**
     * All cache engines for the dedicated-host tile grid — includes Pennant-gated
     * engines as coming-soon rows so operators see Valkey/KeyDB/etc. even when
     * {@see FilterServerProvisionOptionsForCreateForm} strips them from pickers.
     *
     * @return list<array{id: string, label: string, coming_soon?: bool}>
     */
    protected function dedicatedCacheEngineOptions(array $provisionOptions): array
    {
        $options = collect($provisionOptions['cache_services'] ?? [])
            ->filter(fn (array $row): bool => ($row['id'] ?? '') !== 'none')
            ->values();

        $presentIds = $options->pluck('id')->all();

        foreach (CacheEngineAvailability::GATED_ENGINES as $engine) {
            if (in_array($engine, $presentIds, true)) {
                continue;
            }

            if (! CacheEngineAvailability::isComingSoon($engine)) {
                continue;
            }

            if (! $this->dedicatedCacheEngineAllowedForProvider($engine)) {
                continue;
            }

            $configRow = collect(config('server_provision_options.cache_services', []))
                ->firstWhere('id', $engine);

            if (! is_array($configRow)) {
                continue;
            }

            $options->push([
                'id' => $engine,
                'label' => (string) ($configRow['label'] ?? $engine),
                'coming_soon' => true,
            ]);
        }

        return $options->all();
    }

    protected function dedicatedCacheEngineAllowedForProvider(string $engine): bool
    {
        $row = collect(config('server_provision_options.cache_services', []))
            ->firstWhere('id', $engine);

        if (! is_array($row)) {
            return false;
        }

        $exclude = $row['exclude_providers'] ?? null;
        if (is_array($exclude) && in_array($this->form->type, $exclude, true)) {
            return false;
        }

        $providers = $row['providers'] ?? null;
        if (is_array($providers) && $providers !== [] && ! in_array($this->form->type, $providers, true)) {
            return false;
        }

        return true;
    }

    /**
     * Server purposes chosen on Step 2 that ship a fixed stack — no Laravel/Rails
     * template grid on Step 3.
     */
    protected function isDedicatedServerPurposeRole(?string $role = null): bool
    {
        return in_array($role ?? $this->form->server_role, [
            'redis',
            'valkey',
            'database',
            'load_balancer',
            'worker',
            'plain',
            'docker',
        ], true);
    }

    protected function syncInstallProfileForServerRole(): void
    {
        if (in_array($this->form->type, ['digitalocean_functions', 'digitalocean_kubernetes', 'aws_kubernetes', 'aws_lambda'], true)) {
            return;
        }

        $profileId = $this->installProfileIdForServerRole($this->form->server_role);
        if ($profileId !== null) {
            $profile = collect(config('server_provision_options.install_profiles', []))
                ->firstWhere('id', $profileId);
            if (is_array($profile)) {
                $this->form->install_profile = $profileId;
                foreach (['server_role', 'cache_service', 'webserver', 'php_version', 'database', 'setup_script_key'] as $field) {
                    if (array_key_exists($field, $profile) && is_string($profile[$field])) {
                        $this->form->{$field} = $profile[$field];
                    }
                }
                $this->normalizeDedicatedCacheServerForm();
                $this->syncProvisionPreferenceFields();

                return;
            }
        }

        $this->syncProvisionPreferenceFields();
        $this->ensureInstallProfileMatchesCurrentSelections();
    }

    protected function notifySizeRoleGuidance(): void
    {
        if ($this->form->mode !== 'provider' || $this->form->size === '') {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $catalog = $this->resolveServerCreateCatalog($org);
        $mismatch = $this->sizeRoleMismatchForForm($catalog);
        if ($mismatch === null) {
            return;
        }

        $message = $mismatch['detail'];
        if ($mismatch['suggested_size'] !== '') {
            $message .= ' '.(__('Consider :plan instead.', ['plan' => $mismatch['suggested_label']]));
        }

        $this->dispatch(
            'toast',
            message: $message,
            type: $mismatch['state'] === 'too_small' ? 'warning' : 'info',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sizes
     * @param  array<string, array{state?: string, label?: string, detail?: string}>  $recommendations
     */
    protected function firstGoodStartingPointSizeValue(array $sizes, array $recommendations): string
    {
        foreach ($sizes as $size) {
            $value = (string) ($size['value'] ?? '');
            if ($value !== '' && ($recommendations[$value]['state'] ?? null) === 'good_starting_point') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{state: string, label: string, detail: string, suggested_size: string, suggested_label: string}|null
     */
    protected function sizeRoleMismatchForForm(array $catalog): ?array
    {
        if ($this->form->mode !== 'provider' || $this->form->size === '') {
            return null;
        }

        $sizes = is_array($catalog['sizes'] ?? null) ? $catalog['sizes'] : [];
        $recommendations = RecommendServerCreateSizes::run($this->form->server_role, $sizes);
        $current = $recommendations[$this->form->size] ?? null;
        $state = $current['state'] ?? null;

        if (! in_array($state, ['too_small', 'overkill'], true)) {
            return null;
        }

        $suggested = $this->firstGoodStartingPointSizeValue($sizes, $recommendations);
        $suggestedLabel = $suggested;
        foreach ($sizes as $size) {
            if ((string) ($size['value'] ?? '') === $suggested) {
                $suggestedLabel = (string) ($size['label'] ?? $suggested);
                break;
            }
        }

        return [
            'state' => (string) $state,
            'label' => (string) ($current['label'] ?? ''),
            'detail' => (string) ($current['detail'] ?? ''),
            'suggested_size' => $suggested,
            'suggested_label' => $suggestedLabel,
        ];
    }

    protected function roleSizingTip(?string $serverRole): string
    {
        return match ($serverRole) {
            'redis', 'valkey' => __('Redis and Valkey are memory-bound — favor RAM over vCPU when in doubt.'),
            'database' => __('Database nodes need RAM for working sets and disk for data growth.'),
            'application' => __('Web servers run web, PHP, cache, and database together — leave RAM headroom.'),
            'worker' => __('Worker hosts need steady CPU and memory for queue throughput.'),
            'load_balancer' => __('Load balancers route traffic — smaller plans are usually enough.'),
            'plain' => __('Plain hosts are minimal — pick the smallest plan that fits your manual stack.'),
            'docker' => __('Docker hosts need memory for images and running containers.'),
            default => __('Pick a server purpose above to tune size recommendations.'),
        };
    }

    protected function serverRoleLabel(string $serverRole): string
    {
        $role = collect(config('server_provision_options.server_roles', []))
            ->firstWhere('id', $serverRole);

        if (is_array($role) && filled($role['label'] ?? null)) {
            return (string) $role['label'];
        }

        return str($serverRole)->replace('_', ' ')->title()->toString();
    }

    protected function resetCustomConnectionTestState(): void
    {
        $this->customConnectionTestState = 'idle';
        $this->customConnectionTestMessage = '';
        $this->customConnectionTestedAt = null;
        $this->customConnectionTestSignature = null;
    }

    protected function currentCustomConnectionSignature(): string
    {
        return sha1(implode('|', [
            $this->form->ip_address,
            $this->form->ssh_port,
            $this->form->ssh_user,
            $this->form->ssh_private_key,
        ]));
    }

    protected function mergeValidationException(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }
}
