<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;
use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    use ManagesProviderCredentials;

    public ServerCreateForm $form;

    public string $active_provider = 'digitalocean';

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

    public function render(): View
    {
        $this->authorize('create', Server::class);

        $org = auth()->user()->currentOrganization();

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
        $billingUrl = $org ? route('subscription.show', $org) : null;
        $setupScripts = config('setup_scripts.scripts', []);
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

        return view('livewire.servers.create', [
            'catalog' => $catalog,
            'providerCards' => ListServerProviderCards::run($org),
            'providerNav' => CredentialsIndex::credentialProviderNav(),
            'setupScripts' => $setupScripts,
            'provisionOptions' => $provisionOptions,
            'canCreateServer' => $canCreateServer,
            'billingUrl' => $billingUrl,
            'hasAnyProviderCredentials' => $hasAnyProviderCredentials,
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

        $this->form->size = $this->smallestSizeValue($catalog['sizes'] ?? []);
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
