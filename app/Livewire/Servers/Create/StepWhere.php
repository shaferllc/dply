<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;
use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ListExistingProviderServers;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Services\DigitalOceanService;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Step 2 of the create-server wizard. "Where it runs":
 *   provider mode → provider tile + account + region + size
 *   custom mode  → host kind + IP / port / SSH user + private key + test button
 */
#[Layout('layouts.app')]
class StepWhere extends Component
{
    use InteractsWithServerCreateDraft;
    use ManagesProviderCredentials;
    use ServerCreateActions;

    public ServerCreateForm $form;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        if ($redirect = $this->enforceDraftGate()) {
            return $redirect;
        }

        $this->hydrateFormFromDraft($this->form, $this->currentDraft());
        // or the first credentialled provider if blank.
        if ($this->form->mode === 'provider') {
            if ($this->form->provider_host_kind === 'kubernetes' && $this->form->type === '') {
                // Landed on this step with K8s already picked but no provider — try the
                // singleton auto-pick first so the cluster fetch + cost preview can run.
                $this->autoSelectSingletonKubernetesProvider();
            }

            if ($this->form->type === '' || $this->form->type === 'custom') {
                if ($this->form->provider_host_kind !== 'kubernetes') {
                    $this->applyCloudDefaults($this->defaultProvisionProvider());
                }
            } else {
                $this->active_provider = $this->form->type;
            }

            // If there's only one credential / region / size available, auto-pick it
            // so the user doesn't have to open a dropdown to confirm a single option.
            $this->autoSelectSingleOptions();
        }

        return null;
    }

    /**
     * Pre-fill picker fields when there's only one option available — saves the user a click
     * each time we have an unambiguous choice (e.g., a single saved credential for the provider,
     * or a provider with only one region or one plan size in the current scope).
     */
    protected function autoSelectSingleOptions(): void
    {
        $org = auth()->user()?->currentOrganization();
        if (! $org || $this->form->mode !== 'provider' || $this->form->type === '' || $this->form->type === 'custom') {
            return;
        }

        $catalog = $this->resolveServerCreateCatalog($org);
        $credentials = $catalog['credentials'] instanceof Collection
            ? $catalog['credentials']
            : collect();

        if ($this->form->provider_credential_id === '') {
            if ($credentials->count() === 1) {
                $this->form->provider_credential_id = (string) $credentials->first()->id;
                // Picking a credential changes the catalog source — refresh the memo.
                $this->memoServerCreateCatalog = null;
                $this->memoServerCreateCatalogKey = null;
                $this->syncProvisionPreferenceFields($credentials);
                $catalog = $this->resolveServerCreateCatalog($org);
            } else {
                return;
            }
        }

        if ($this->form->provider_credential_id === '') {
            return;
        }

        $regions = $catalog['regions'] ?? [];

        // Region: if empty, prefer West Coast US (per project default) and fall through
        // to the user's country tokens — handled by preferredRegionValue. Always picks
        // *some* region when at least one exists, never leaves the user with a blank.
        if ($this->form->region === '' && $regions !== []) {
            $this->form->region = $this->preferredRegionValue($regions);

            // DigitalOcean sizes depend on region — drop the catalog memo so the
            // next read reloads filtered by the newly-defaulted region.
            if (in_array($this->form->type, ['digitalocean'], true)) {
                $this->memoServerCreateCatalog = null;
                $this->memoServerCreateCatalogKey = null;
                $catalog = $this->resolveServerCreateCatalog($org);
            }
        }

        $sizes = $catalog['sizes'] ?? [];

        // Size: if empty, default to the cheapest plan available.
        if ($this->form->size === '' && $sizes !== []) {
            $this->form->size = $this->recommendedSizeValue($sizes, $this->form->server_role);
        }
    }

    public function chooseHostKind(string $kind): void
    {
        if (! in_array($kind, ['vm', 'docker'], true)) {
            return;
        }
        $this->form->custom_host_kind = $kind;
    }

    public function chooseProviderHostKind(string $kind): void
    {
        if (! in_array($kind, ['vm', 'docker', 'kubernetes'], true)) {
            return;
        }
        $this->form->provider_host_kind = $kind;

        // For K8s the user picks the provider (DO or AWS) via the tile picker
        // below, which calls chooseProvider() and resolves form.type to
        // {provider}_kubernetes. Clearing any stale K8s type pin here lets the
        // user freely toggle between vm/docker/kubernetes without ghost state.
        if (in_array($this->form->type, ['digitalocean_kubernetes', 'aws_kubernetes'], true) && $kind !== 'kubernetes') {
            $this->form->type = '';
            $this->active_provider = '';
        }
        if ($kind === 'kubernetes') {
            // If a non-K8s provider was previously selected, drop the type so
            // the picker re-opens with a clean DO-or-AWS choice.
            if (! in_array($this->form->type, ['digitalocean_kubernetes', 'aws_kubernetes'], true)) {
                $this->form->type = '';
                $this->active_provider = '';
            }
            $this->memoServerCreateCatalog = null;
            $this->memoServerCreateCatalogKey = null;
            $this->autoSelectSingletonKubernetesProvider();
        }
    }

    /**
     * When the user has credentials for exactly one of the K8s-capable providers
     * (DigitalOcean DOKS or AWS EKS), pick it for them so the cost preview can
     * resolve immediately instead of stalling on an empty provider tile.
     */
    private function autoSelectSingletonKubernetesProvider(): void
    {
        if ($this->form->type !== '') {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $candidates = [];
        foreach (['digitalocean', 'aws'] as $provider) {
            $type = $provider.'_kubernetes';
            if (! ServerProviderGate::enabled($type)) {
                continue;
            }
            if (GetProviderCredentialsForServerType::run($org, $type)->isNotEmpty()) {
                $candidates[] = $provider;
            }
        }

        if (count($candidates) === 1) {
            $this->chooseProvider($candidates[0]);
        }
    }

    public function updatedFormProviderCredentialId(): void
    {
        // Trait's version syncs stack defaults; we additionally pick a single region/size if available.
        if ($this->form->type !== 'custom') {
            $this->syncProvisionPreferenceFields();
            $this->memoServerCreateCatalog = null;
            $this->memoServerCreateCatalogKey = null;
            $this->autoSelectSingleOptions();
        }
    }

    public function chooseServerRole(string $role): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $catalog = $this->resolveServerCreateCatalog($org);

        $hasLinkedCredential = $this->form->provider_credential_id !== ''
            && ($catalog['credentials'] instanceof Collection)
            && $catalog['credentials']->contains('id', $this->form->provider_credential_id);

        $allowed = collect(
            FilterServerProvisionOptionsForCreateForm::run(
                $this->form->type,
                $hasLinkedCredential,
                $role,
            )['server_roles'] ?? []
        )->pluck('id')->all();

        if ($allowed !== [] && ! in_array($role, $allowed, true)) {
            return;
        }

        $this->form->server_role = $role;
        $this->syncInstallProfileForServerRole();
        $this->notifySizeRoleGuidance();
    }

    public function updatedFormServerRole(): void
    {
        $this->syncInstallProfileForServerRole();
        $this->notifySizeRoleGuidance();
    }

    public function chooseProvider(string $provider): void
    {
        // For K8s the tile id is the bare provider name (digitalocean / aws)
        // but the form.type the wizard ultimately stores is the K8s-suffixed
        // variant — that's what StoreServerFromCreateForm dispatches on.
        if ($this->form->provider_host_kind === 'kubernetes') {
            if (! in_array($provider, ['digitalocean', 'aws'], true)) {
                return;
            }
            $type = $provider.'_kubernetes';
            if (! ServerProviderGate::enabled($type)) {
                return;
            }
            $this->form->mode = 'provider';
            $this->form->type = $type;
            $this->active_provider = $provider;
            $this->memoServerCreateCatalog = null;
            $this->memoServerCreateCatalogKey = null;
            $this->autoSelectSingleOptions();

            return;
        }

        if (! ServerProviderGate::enabled($provider)) {
            return;
        }
        $this->form->mode = 'provider';
        $this->active_provider = $provider;
        $this->applyCloudDefaults($provider);
        $this->autoSelectSingleOptions();
    }

    public function loadDoVpcs(): void
    {
        if ($this->form->type !== 'digitalocean' || $this->form->region === '' || $this->form->provider_credential_id === '') {
            return;
        }

        $credential = ProviderCredential::query()->find($this->form->provider_credential_id);
        if (! $credential) {
            return;
        }

        $this->form->do_vpcs_loading = true;

        try {
            $do = new DigitalOceanService($credential);
            $this->form->do_vpcs = $do->listVpcs($this->form->region);
        } catch (\Throwable) {
            $this->form->do_vpcs = [];
        }

        $this->form->do_vpcs_loading = false;
    }

    #[On('personal-ssh-key-created')]
    public function refreshPersonalSshKeyState(): void
    {
        // Triggers re-render so the connection panel reflects newly-saved keys.
    }

    #[On('provider-credential-created')]
    public function applyStoredProviderCredential(?string $provider = null, mixed $credentialId = null): void
    {
        if (! is_string($provider) || $provider === '' || $credentialId === null || $credentialId === '') {
            return;
        }

        $formProvider = str_replace('_kubernetes', '', $this->form->type);
        if ($formProvider !== $provider) {
            return;
        }

        $this->form->provider_credential_id = (string) $credentialId;
        $this->active_provider = $provider;
        $this->applyCloudDefaults($provider);
        $this->autoSelectSingleOptions();
    }

    public function previous(): mixed
    {
        $this->saveDraftFromForm($this->form);

        return $this->redirect(route(self::routeNameForStep(1)), navigate: true);
    }

    public function next(): mixed
    {
        $this->authorize('create', Server::class);

        if ($this->form->mode === 'provider') {
            $isKubernetes = $this->form->provider_host_kind === 'kubernetes';

            $rules = [
                'form.type' => ['required', 'string', 'max:64'],
                'form.provider_host_kind' => ['required', Rule::in(['vm', 'docker', 'kubernetes'])],
                'form.provider_credential_id' => ['required', 'string'],
            ];
            $attributes = [
                'form.type' => __('provider'),
                'form.provider_host_kind' => __('host kind'),
                'form.provider_credential_id' => __('account'),
            ];

            // VM and Docker hosts pick a region + plan; K8s servers infer
            // both from the selected cluster (chosen on the next step).
            if (! $isKubernetes) {
                $rules['form.region'] = ['required', 'string'];
                $rules['form.size'] = ['required', 'string'];
                $attributes['form.region'] = __('region');
                $attributes['form.size'] = __('plan');
            }

            $this->validate($rules, attributes: $attributes);
        } else {
            $this->validate([
                'form.custom_host_kind' => ['required', Rule::in(['vm', 'docker'])],
                'form.ip_address' => ['required', 'string', 'max:255'],
                'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'form.ssh_user' => ['required', 'string', 'max:64'],
                'form.ssh_private_key' => ['required', 'string', 'min:32'],
            ], attributes: [
                'form.custom_host_kind' => __('host kind'),
                'form.ip_address' => __('IP address'),
                'form.ssh_port' => __('SSH port'),
                'form.ssh_user' => __('SSH user'),
                'form.ssh_private_key' => __('private key'),
            ]);
        }

        // Docker host (either mode) has no stack step — jump to Review (advance high-water mark to 4).
        $skipsStack = ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker')
            || ($this->form->mode === 'provider' && $this->form->provider_host_kind === 'docker');
        $next = $skipsStack ? 4 : 3;

        $this->saveDraftFromForm($this->form, advanceTo: $next);

        return $this->redirect(route(self::routeNameForStep($next)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 2;
    }

    /**
     * Provider tile picker. For VM/Docker hosts the full provider list applies;
     * for Kubernetes hosts only DigitalOcean (DOKS) and AWS (EKS) are valid
     * targets in this release.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveProviderCards(): array
    {
        $cards = $this->provisionProviderCardsFromList($this->listServerProviderCards());
        if ($this->form->provider_host_kind !== 'kubernetes') {
            return $cards;
        }

        return array_values(array_filter(
            $cards,
            fn (array $card): bool => in_array($card['id'] ?? '', ['digitalocean', 'aws'], true),
        ));
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);
        $catalog = $context['catalog'];
        $regionLabels = collect(is_array($catalog['regions'] ?? null) ? $catalog['regions'] : [])
            ->mapWithKeys(fn (array $region): array => [(string) ($region['value'] ?? '') => (string) ($region['label'] ?? '')])
            ->filter(fn (string $label, string $value): bool => $value !== '')
            ->all();

        $existingProviderServers = ($org !== null && $this->form->type !== '' && $this->form->type !== 'custom')
            ? ListExistingProviderServers::run($org, $this->form->type)
            : [];

        $existingServersByRegion = ($org !== null && $this->form->type !== '' && $this->form->type !== 'custom')
            ? ListExistingProviderServers::make()->regionCounts($org, $this->form->type)
            : [];

        // Private networks we already track for this account — let the user pick
        // one instead of hunting for the ID in the Hetzner console.
        $privateNetworks = ($org !== null && $this->form->type === 'hetzner' && $this->form->provider_credential_id !== '')
            ? \App\Models\PrivateNetwork::query()
                ->where('organization_id', $org->id)
                ->where('provider', \App\Models\PrivateNetwork::PROVIDER_HETZNER)
                ->where('provider_credential_id', $this->form->provider_credential_id)
                ->whereNotNull('provider_id')
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.servers.create.step-where', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 2,
            'catalog' => $catalog,
            'preflight' => $context['preflight'],
            'provisionOptions' => $context['provisionOptions'],
            'hasAnyProviderCredentials' => $context['hasAnyProviderCredentials'],
            'hasLinkedCredential' => $context['hasLinkedCredential'],
            'providerCards' => $this->resolveProviderCards(),
            'credentialProviderNav' => $this->memoCredentialProviderNav(),
            'selectedServerRole' => collect($context['provisionOptions']['server_roles'] ?? [])
                ->firstWhere('id', $this->form->server_role),
            'roleSizingTip' => $this->roleSizingTip($this->form->server_role),
            'existingProviderServers' => $existingProviderServers,
            'existingServersByRegion' => $existingServersByRegion,
            'regionLabels' => $regionLabels,
            'privateNetworks' => $privateNetworks,
        ]);
    }
}
