<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\BuildProviderCredentialHealth;
use App\Actions\Servers\BuildServerCreatePreflight;
use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;
use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\RecommendServerCreateSizes;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    use ManagesProviderCredentials;

    public ServerCreateForm $form;

    public string $active_provider = 'digitalocean';

    public string $customConnectionTestState = 'idle';

    public string $customConnectionTestMessage = '';

    public ?string $customConnectionTestedAt = null;

    public ?string $customConnectionTestSignature = null;

    public function mount(): void
    {
        $ids = CredentialsIndex::credentialProviderIds();
        if ($ids !== [] && ! in_array($this->active_provider, $ids, true)) {
            $this->active_provider = $ids[0];
        }

        if (! ServerProviderGate::enabled($this->form->type)) {
            $this->form->type = ServerProviderGate::defaultServerCreateType();
        }

        $org = auth()->user()?->currentOrganization();
        $hasAnyProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;
        if (! $hasAnyProviderCredentials) {
            $this->form->type = 'custom';
        } elseif ($this->form->provider_credential_id === '') {
            $this->applyCloudDefaults();
        }
        if ($this->form->name === '') {
            $this->form->name = $this->generateServerName();
        }

        if (! $org || $this->form->type === 'custom') {
            return;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $this->form->type);
        if ($credentials->isNotEmpty() && $this->form->provider_credential_id === '') {
            $this->form->provider_credential_id = (string) $credentials->first()->id;
        }

        $this->syncProvisionPreferenceFields();
    }

    public function updatedActiveProvider(mixed $value): void
    {
        $ids = CredentialsIndex::credentialProviderIds();
        if (! is_string($value) || ! in_array($value, $ids, true)) {
            $this->active_provider = $ids[0] ?? 'digitalocean';
        }
    }

    public function regenerateServerName(): void
    {
        $this->form->name = $this->generateServerName();
    }

    public function afterProviderCredentialStored(string $provider): void
    {
        $this->active_provider = $provider;
        $this->applyCloudDefaults($provider);
    }

    public function updatedFormInstallProfile(): void
    {
        $this->applyInstallProfile();
    }

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

        $preflight = $this->buildPreflightContext($org);
        if (! $preflight['preflight']['can_submit']) {
            foreach ($preflight['preflight']['blocking_fields'] as $field => $message) {
                $this->addError($field, $message);
            }
            if ($preflight['preflight']['blocking_fields'] === []) {
                $this->addError('org', $preflight['preflight']['summary']);
            }

            return null;
        }

        try {
            $server = StoreServerFromCreateForm::run($user, $org, $this->form);
        } catch (ValidationException $e) {
            $this->mergeValidationException($e);

            return null;
        }

        $this->flashSuccessForServerType($this->form->type);

        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    public function updatedFormType(): void
    {
        $this->form->provider_credential_id = '';
        $this->form->region = '';
        $this->form->size = '';
        $this->resetCustomConnectionTestState();

        $org = auth()->user()?->currentOrganization();
        if (! $org || $this->form->type === 'custom') {
            return;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $this->form->type);
        if ($credentials->isNotEmpty()) {
            $this->form->provider_credential_id = (string) $credentials->first()->id;
        }

        $this->syncProvisionPreferenceFields();
    }

    public function updatedFormProviderCredentialId(): void
    {
        $this->form->region = '';
        $this->form->size = '';
        $this->syncProvisionPreferenceFields();
    }

    public function updatedFormServerRole(): void
    {
        $this->syncProvisionPreferenceFields();
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

    public function testCustomConnection(): void
    {
        $this->resetErrorBag([
            'ip_address',
            'ssh_port',
            'ssh_user',
            'ssh_private_key',
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
            $this->mergeValidationException($e);
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

    public function render(): View
    {
        $this->authorize('create', Server::class);

        $org = auth()->user()->currentOrganization();
        $context = $this->buildPreflightContext($org);
        $catalog = $context['catalog'];
        $canCreateServer = $context['canCreateServer'];
        $billingUrl = $org ? route('subscription.show', $org) : null;
        $setupScripts = config('setup_scripts.scripts', []);
        $installProfiles = config('server_provision_options.install_profiles', []);

        return view('livewire.servers.create', [
            'catalog' => $catalog,
            'providerCards' => ListServerProviderCards::run($org),
            'providerNav' => CredentialsIndex::credentialProviderNav(),
            'setupScripts' => $setupScripts,
            'installProfiles' => $installProfiles,
            'provisionOptions' => $context['provisionOptions'],
            'preflight' => $context['preflight'],
            'canCreateServer' => $canCreateServer,
            'billingUrl' => $billingUrl,
            'hasAnyProviderCredentials' => $context['hasAnyProviderCredentials'],
            'credentials' => $org
                ? ProviderCredential::where('organization_id', $org->id)->latest()->get()
                : collect(),
            'activeProviderLabel' => CredentialsIndex::credentialProviderNav() !== []
                ? (function (): string {
                    foreach (CredentialsIndex::credentialProviderNav() as $group) {
                        foreach ($group['items'] as $item) {
                            if ($item['id'] === $this->active_provider) {
                                return $item['label'];
                            }
                        }
                    }

                    return $this->active_provider;
                })()
                : $this->active_provider,
            'digitalOceanOAuthConfigured' => filled(config('services.digitalocean_oauth.client_id')) && filled(config('services.digitalocean_oauth.client_secret')),
        ]);
    }

    protected function syncProvisionPreferenceFields(): void
    {
        if ($this->form->type === 'custom') {
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
            $type = collect(ListServerProviderCards::run($org))
                ->first(fn (array $card): bool => $card['id'] !== 'custom' && ($card['linked'] ?? false))['id'] ?? null;
        }

        if (! is_string($type) || $type === '') {
            return;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $type);
        $credential = $credentials->first();
        if (! $credential) {
            return;
        }

        $this->form->type = $type;
        $this->form->provider_credential_id = (string) $credential->id;
        $this->active_provider = $type;
        $this->applyInstallProfile();
        $this->syncProvisionPreferenceFields();

        $catalog = ResolveServerCreateCatalog::run(
            $org,
            $this->form->type,
            $this->form->provider_credential_id,
            '',
        );

        $this->form->region = $this->preferredRegionValue($catalog['regions'] ?? []);

        $catalog = ResolveServerCreateCatalog::run(
            $org,
            $this->form->type,
            $this->form->provider_credential_id,
            $this->form->region,
        );

        $this->form->size = $this->recommendedSizeValue($catalog['sizes'] ?? [], $this->form->server_role);
    }

    protected function preferredRegionValue(array $regions): string
    {
        if ($regions === []) {
            return '';
        }

        $countryCode = strtoupper((string) (auth()->user()?->country_code ?? ''));
        $preferredTokens = match ($countryCode) {
            'US', 'CA' => ['nyc', 'tor', 'sfo', 'sea', 'atl', 'chi', 'iad'],
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

    protected function recommendedSizeValue(array $sizes, string $serverRole): string
    {
        $recommendations = RecommendServerCreateSizes::run($serverRole, $sizes);
        foreach ($sizes as $size) {
            $value = (string) ($size['value'] ?? '');
            if (($recommendations[$value]['state'] ?? null) === 'good_starting_point') {
                return $value;
            }
        }

        return $this->smallestSizeValue($sizes);
    }

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
    protected function buildPreflightContext($org): array
    {
        $catalog = $org
            ? ResolveServerCreateCatalog::run(
                $org,
                $this->form->type,
                $this->form->provider_credential_id,
                $this->form->region,
            )
            : [
                'credentials' => collect(),
                'regions' => [],
                'sizes' => [],
                'region_label' => __('Region'),
                'size_label' => __('Plan / size'),
            ];

        $canCreateServer = $org ? $org->canCreateServer() : false;
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
        $selectedCredential = $catalog['credentials'] instanceof \Illuminate\Support\Collection
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

    protected function flashSuccessForServerType(string $type): void
    {
        Session::flash('success', match ($type) {
            'equinix_metal' => __('Bare metal can take 5–10 minutes.'),
            'fly_io' => __('Fly.io machine is being created.'),
            'aws' => __('AWS EC2 instance is being created. This usually takes 1–2 minutes.'),
            'custom' => __('Server added.'),
            default => __('Server is being created. This usually takes 1–2 minutes.'),
        });
    }

    protected function generateServerName(): string
    {
        $adjectives = [
            'steady',
            'brisk',
            'bold',
            'calm',
            'bright',
            'swift',
            'sharp',
            'amber',
            'silver',
            'crisp',
        ];
        $nouns = [
            'otter',
            'falcon',
            'harbor',
            'summit',
            'spruce',
            'signal',
            'meadow',
            'comet',
            'anchor',
            'cinder',
        ];

        return Str::slug($adjectives[array_rand($adjectives)].'-'.$nouns[array_rand($nouns)]);
    }
}
