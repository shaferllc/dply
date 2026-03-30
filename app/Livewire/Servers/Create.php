<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\FilterServerProvisionOptionsForCreateForm;
use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Server;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public ServerCreateForm $form;

    public function mount(): void
    {
        if (! ServerProviderGate::enabled($this->form->type)) {
            $this->form->type = ServerProviderGate::defaultServerCreateType();
        }

        $org = auth()->user()?->currentOrganization();
        if (! $org || $this->form->type === 'custom') {
            return;
        }

        $credentials = GetProviderCredentialsForServerType::run($org, $this->form->type);
        if ($credentials->isNotEmpty() && $this->form->provider_credential_id === '') {
            $this->form->provider_credential_id = (string) $credentials->first()->id;
        }

        $this->syncProvisionPreferenceFields();
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
            'setupScripts' => $setupScripts,
            'provisionOptions' => $provisionOptions,
            'canCreateServer' => $canCreateServer,
            'billingUrl' => $billingUrl,
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
}
