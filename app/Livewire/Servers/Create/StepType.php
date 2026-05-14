<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Models\ForgeServer;
use App\Models\PloiServer;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Support\Servers\ServerNameGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StepType extends Component
{
    use InteractsWithServerCreateDraft;

    public ServerCreateForm $form;

    public ?string $launchSource = null;

    /** Used to show the "Create the remote Docker host first" framing on Step 1. */
    public bool $dockerHostHinted = false;

    /** Set from ?from_ploi_server= when the user enters the wizard via the Ploi inventory. */
    public ?string $migrationSourcePloiServerId = null;

    /** Set from ?from_forge_server= when the user enters the wizard via the Forge inventory. */
    public ?string $migrationSourceForgeServerId = null;

    public ?string $migrationSourceLabel = null;

    /** 'ploi' | 'forge' | null — which source family the banner reflects. */
    public ?string $migrationSourceKind = null;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        $draft = $this->currentDraft();

        // If the user has a draft already in progress past step 1, jump them to where
        // they left off so they don't have to walk forward through completed steps.
        // They can still edit Step 1 by clicking back from any later step (the stepper
        // pills are clickable for any reached step).
        if ($draft !== null && $draft->step > 1 && request()->query('edit') !== '1') {
            return $this->redirect(route(self::routeNameForStep($draft->step)), navigate: true);
        }

        $this->hydrateFormFromDraft($this->form, $draft);

        if ($draft === null) {
            // Defaults for a brand-new draft.
            if ($this->form->name === '') {
                $this->form->name = ServerNameGenerator::generate();
            }
            if ($this->form->mode === '' || ! in_array($this->form->mode, ['provider', 'custom'], true)) {
                $this->form->mode = 'provider';
            }

            $requestedSource = request()->query('source');
            if (is_string($requestedSource) && $requestedSource !== '') {
                $this->launchSource = $requestedSource;
            }

            $requestedHostTarget = request()->query('host_target');
            if ($requestedHostTarget === 'docker') {
                $this->dockerHostHinted = true;
                $this->form->mode = 'provider';
                $this->form->provider_host_kind = 'docker';
            }
        }

        $this->applyPloiMigrationContext($draft);
        $this->applyForgeMigrationContext($draft);

        return null;
    }

    /**
     * Same shape as the Ploi handler; stashes the Forge server id under
     * _forge_migration_source_id in the draft payload.
     */
    protected function applyForgeMigrationContext(?ServerCreateDraft $draft): void
    {
        if ($draft !== null && isset($draft->payload['_forge_migration_source_id'])) {
            $this->hydrateMigrationContextFromForgeServer((string) $draft->payload['_forge_migration_source_id']);

            return;
        }
        $param = request()->query('from_forge_server');
        if (is_string($param) && $param !== '') {
            $this->hydrateMigrationContextFromForgeServer($param);
        }
    }

    protected function hydrateMigrationContextFromForgeServer(string $forgeServerId): void
    {
        $org = $this->currentOrganization();
        if ($org === null) {
            return;
        }
        $forgeServer = ForgeServer::query()
            ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
            ->find($forgeServerId);
        if ($forgeServer === null) {
            return;
        }
        $this->migrationSourceForgeServerId = $forgeServer->id;
        $this->migrationSourceLabel = $forgeServer->name;
        $this->migrationSourceKind = 'forge';
    }

    /**
     * Hydrate the migration-from-Ploi banner state from either an existing draft
     * (mid-wizard) or the ?from_ploi_server= query param (entering Step 1). Once
     * a draft is created in next(), the Ploi server id rides along in the payload
     * under the _ploi_migration_source_id key — out of the form-field namespace so
     * it doesn't conflict with any current or future ServerCreateForm property.
     */
    protected function applyPloiMigrationContext(?ServerCreateDraft $draft): void
    {
        // Prefer the draft when present — survives mid-wizard refreshes.
        if ($draft !== null && isset($draft->payload['_ploi_migration_source_id'])) {
            $stashed = (string) $draft->payload['_ploi_migration_source_id'];
            $this->hydrateMigrationContextFromPloiServer($stashed);

            return;
        }

        $param = request()->query('from_ploi_server');
        if (is_string($param) && $param !== '') {
            $this->hydrateMigrationContextFromPloiServer($param);
        }
    }

    protected function hydrateMigrationContextFromPloiServer(string $ploiServerId): void
    {
        $org = $this->currentOrganization();
        if ($org === null) {
            return;
        }

        $ploiServer = PloiServer::query()
            ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
            ->find($ploiServerId);

        if ($ploiServer === null) {
            return;
        }

        $this->migrationSourcePloiServerId = $ploiServer->id;
        $this->migrationSourceLabel = $ploiServer->name;
        $this->migrationSourceKind = 'ploi';
    }

    public function regenerateName(): void
    {
        $this->form->name = ServerNameGenerator::generate();
    }

    public function chooseProviderMode(): void
    {
        $this->form->mode = 'provider';
        // Provider tile is selected on Step 2; clear any stale 'custom' marker on type.
        if ($this->form->type === 'custom') {
            $this->form->type = '';
        }
    }

    public function chooseCustomMode(): void
    {
        $this->form->mode = 'custom';
        $this->form->type = 'custom';
    }

    public function next(): mixed
    {
        $this->authorize('create', Server::class);

        $this->validate([
            'form.mode' => ['required', Rule::in(['provider', 'custom'])],
            'form.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], attributes: [
            'form.mode' => __('server type'),
            'form.name' => __('server name'),
        ]);

        if ($this->form->mode === 'custom' && $this->form->type !== 'custom') {
            $this->form->type = 'custom';
        }

        $draft = $this->saveDraftFromForm($this->form, advanceTo: 2);

        // Stash the migration source on the draft so it survives subsequent steps.
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $payloadChanged = false;
        if ($this->migrationSourcePloiServerId !== null) {
            $payload['_ploi_migration_source_id'] = $this->migrationSourcePloiServerId;
            $payloadChanged = true;
        }
        if ($this->migrationSourceForgeServerId !== null) {
            $payload['_forge_migration_source_id'] = $this->migrationSourceForgeServerId;
            $payloadChanged = true;
        }
        if ($payloadChanged) {
            $draft->payload = $payload;
            $draft->save();
        }

        return $this->redirect(route(self::routeNameForStep(2)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 1;
    }

    public function render(): View
    {
        return view('livewire.servers.create.step-type', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
        ]);
    }
}
