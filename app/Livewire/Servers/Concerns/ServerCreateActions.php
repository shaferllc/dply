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
use App\Services\SshConnection;
use App\Support\ServerProviderGate;
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
        if ($this->form->type === 'scaleway') {
            $this->form->size = '';
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
            $ssh = new SshConnection($server);
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
        $memoSegment = $this->form->type === 'scaleway' ? $selectedRegion : '';
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
     * @return list<array{id: string, label: string, linked: bool}>
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
     * @return list<array{id: string, label: string, linked: bool}>
     */
    protected function provisionProviderCardsFromList(array $cards): array
    {
        return array_values(array_filter(
            $cards,
            fn (array $card): bool => in_array($card['id'], [
                'digitalocean', 'hetzner', 'vultr', 'linode', 'akamai',
                'scaleway', 'upcloud', 'equinix_metal', 'fly_io', 'aws',
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

    protected function syncProvisionPreferenceFields(): void
    {
        if (in_array($this->form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true)) {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        $hasLinkedCredential = $org
            ? GetProviderCredentialsForServerType::run($org, $this->form->type)->isNotEmpty()
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
        $this->syncProvisionPreferenceFields();

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
        $hasLinkedCredential = $org
            ? GetProviderCredentialsForServerType::run($org, $this->form->type)->isNotEmpty()
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
            ),
            'canCreateServer' => $canCreateServer,
            'hasAnyProviderCredentials' => $hasAnyProviderCredentials,
            'hasLinkedCredential' => $hasLinkedCredential,
        ];
    }

    protected function applyInstallProfile(): void
    {
        if (in_array($this->form->type, ['digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true)) {
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
