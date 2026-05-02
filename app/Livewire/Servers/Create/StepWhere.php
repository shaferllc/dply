<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
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

        // Default the active provider tile to whatever the form already has,
        // or the first credentialled provider if blank.
        if ($this->form->mode === 'provider') {
            if ($this->form->type === '' || $this->form->type === 'custom') {
                $this->applyCloudDefaults($this->defaultProvisionProvider());
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

        if ($this->form->provider_credential_id === '') {
            $credentials = GetProviderCredentialsForServerType::run($org, $this->form->type);
            if ($credentials->count() === 1) {
                $this->form->provider_credential_id = (string) $credentials->first()->id;
                // Picking a credential changes the catalog source — refresh the memo.
                $this->memoServerCreateCatalog = null;
                $this->memoServerCreateCatalogKey = null;
                $this->syncProvisionPreferenceFields();
            }
        }

        if ($this->form->provider_credential_id === '') {
            return;
        }

        $catalog = $this->resolveServerCreateCatalog($org);
        $regions = $catalog['regions'] ?? [];

        // Region: if empty, prefer West Coast US (per project default) and fall through
        // to the user's country tokens — handled by preferredRegionValue. Always picks
        // *some* region when at least one exists, never leaves the user with a blank.
        if ($this->form->region === '' && $regions !== []) {
            $this->form->region = $this->preferredRegionValue($regions);

            // Scaleway sizes depend on region — drop the catalog memo so the next read reloads.
            if ($this->form->type === 'scaleway') {
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

    public function chooseProvider(string $provider): void
    {
        if (! ServerProviderGate::enabled($provider)) {
            return;
        }
        $this->form->mode = 'provider';
        $this->active_provider = $provider;
        $this->applyCloudDefaults($provider);
        $this->autoSelectSingleOptions();
    }

    #[On('personal-ssh-key-created')]
    public function refreshPersonalSshKeyState(): void
    {
        // Triggers re-render so the connection panel reflects newly-saved keys.
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
            $this->validate([
                'form.type' => ['required', 'string', 'max:64'],
                'form.provider_credential_id' => ['required', 'string'],
                'form.region' => ['required', 'string'],
                'form.size' => ['required', 'string'],
            ], attributes: [
                'form.type' => __('provider'),
                'form.provider_credential_id' => __('account'),
                'form.region' => __('region'),
                'form.size' => __('plan'),
            ]);
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

        // Custom + Docker host has no stack step — jump to Review (advance high-water mark to 4).
        $skipsStack = $this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker';
        $next = $skipsStack ? 4 : 3;

        $this->saveDraftFromForm($this->form, advanceTo: $next);

        return $this->redirect(route(self::routeNameForStep($next)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 2;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);

        return view('livewire.servers.create.step-where', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 2,
            'catalog' => $context['catalog'],
            'preflight' => $context['preflight'],
            'hasAnyProviderCredentials' => $context['hasAnyProviderCredentials'],
            'hasLinkedCredential' => $context['hasLinkedCredential'],
            'providerCards' => $this->provisionProviderCardsFromList($this->listServerProviderCards()),
            'credentialProviderNav' => $this->memoCredentialProviderNav(),
        ]);
    }
}
