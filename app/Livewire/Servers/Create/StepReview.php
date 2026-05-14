<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Actions\Servers\StoreServerFromCreateForm;
use App\Jobs\Imports\RunMigrationStepJob;
use App\Models\Organization;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\ImportServerMigration;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Services\Imports\MigrationPlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Step 4 of the create-server wizard. Read-only summary, advanced options collapsed,
 * preflight + cost preview, final create button.
 */
#[Layout('layouts.app')]
class StepReview extends Component
{
    use InteractsWithServerCreateDraft;
    use ServerCreateActions;

    public ServerCreateForm $form;

    /**
     * Per-site selection state for the Q10 wizard checklist. Keyed by PloiSite ulid;
     * value is bool (selected). Empty array means "no migration context" — only
     * populated when the draft carries _ploi_migration_source_id.
     *
     * @var array<string, bool>
     */
    public array $migrationSiteSelection = [];

    /** Toggled visible only when this Step 4 is a migration-target server-create. */
    public ?string $migrationSourcePloiServerId = null;

    public ?string $migrationSourceForgeServerId = null;

    public ?string $migrationSourceLabel = null;

    /** 'ploi' | 'forge' | null — drives banner copy + selection wiring. */
    public ?string $migrationSourceKind = null;

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        if ($redirect = $this->enforceDraftGate()) {
            return $redirect;
        }

        $this->hydrateFormFromDraft($this->form, $this->currentDraft());
        $this->hydrateMigrationSelection();

        return null;
    }

    /**
     * Populate $migrationSiteSelection from the draft payload (or default to all
     * eligible sites if not yet set). Each site shows as a row with a checkbox
     * on the review screen; Confirm uses the resulting map.
     */
    protected function hydrateMigrationSelection(): void
    {
        $draft = $this->currentDraft();
        $payload = is_array($draft?->payload ?? null) ? $draft->payload : [];

        // Branch on source. Ploi gets priority if both are somehow set (defensive).
        $ploiSourceId = $payload['_ploi_migration_source_id'] ?? null;
        $forgeSourceId = $payload['_forge_migration_source_id'] ?? null;

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        if (is_string($ploiSourceId) && $ploiSourceId !== '') {
            $this->hydratePloiMigrationSelection($ploiSourceId, $payload, $org);

            return;
        }
        if (is_string($forgeSourceId) && $forgeSourceId !== '') {
            $this->hydrateForgeMigrationSelection($forgeSourceId, $payload, $org);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function hydratePloiMigrationSelection(string $sourceId, array $payload, $org): void
    {
        $ploiServer = \App\Models\PloiServer::query()
            ->with('sites')
            ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
            ->find($sourceId);
        if ($ploiServer === null) {
            return;
        }

        $this->migrationSourcePloiServerId = $ploiServer->id;
        $this->migrationSourceLabel = $ploiServer->name;
        $this->migrationSourceKind = 'ploi';

        $explicit = $payload['_ploi_migration_site_selection'] ?? null;
        foreach ($ploiServer->sites as $site) {
            $key = $site->id;
            if (is_array($explicit) && array_key_exists($key, $explicit)) {
                $this->migrationSiteSelection[$key] = (bool) $explicit[$key];
            } else {
                $this->migrationSiteSelection[$key] = $site->isMigrationEligible() && ! $site->removed_from_source;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function hydrateForgeMigrationSelection(string $sourceId, array $payload, $org): void
    {
        $forgeServer = \App\Models\ForgeServer::query()
            ->with('sites')
            ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
            ->find($sourceId);
        if ($forgeServer === null) {
            return;
        }

        $this->migrationSourceForgeServerId = $forgeServer->id;
        $this->migrationSourceLabel = $forgeServer->name;
        $this->migrationSourceKind = 'forge';

        $explicit = $payload['_forge_migration_site_selection'] ?? null;
        foreach ($forgeServer->sites as $site) {
            $key = $site->id;
            if (is_array($explicit) && array_key_exists($key, $explicit)) {
                $this->migrationSiteSelection[$key] = (bool) $explicit[$key];
            } else {
                $this->migrationSiteSelection[$key] = $site->isMigrationEligible() && ! $site->removed_from_source;
            }
        }
    }

    public function updatedMigrationSiteSelection(): void
    {
        // Persist user toggles into the draft so they survive a refresh / step-back.
        $draft = $this->currentDraft();
        if ($draft === null) {
            return;
        }
        $payload = is_array($draft->payload) ? $draft->payload : [];
        $key = $this->migrationSourceKind === 'forge'
            ? '_forge_migration_site_selection'
            : '_ploi_migration_site_selection';
        $payload[$key] = array_map(
            fn ($v) => (bool) $v,
            $this->migrationSiteSelection,
        );
        $draft->payload = $payload;
        $draft->save();
    }

    public function previous(): mixed
    {
        $this->saveDraftFromForm($this->form);

        // VM-shaped servers go back to "What it runs"; Docker hosts skip step 3.
        $back = ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker') ? 2 : 3;

        return $this->redirect(route(self::routeNameForStep($back)), navigate: true);
    }

    public function store(): mixed
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if (! $user->hasVerifiedEmail()) {
            return $this->redirect(route('verification.notice'), navigate: true)
                ->with('error', __('Please verify your email address before creating a server.'));
        }

        $this->authorize('create', Server::class);

        $org = $user->currentOrganization();
        if (! $org) {
            $this->addError('org', __('Select or create an organization first.'));

            return null;
        }

        if (! in_array($this->form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true) && ! $user->sshKeys()->exists()) {
            return $this->redirectRoute('profile.ssh-keys', [
                'source' => 'servers.create',
                'return_to' => 'servers.create',
            ], navigate: true);
        }

        if (! $org->canCreateServer()) {
            $this->addError('org', __('Server limit reached for your plan. Upgrade to add more.'));

            return null;
        }

        // Persist the latest field state into the draft before running preflight,
        // so a soft failure (validation errors) leaves the draft unsurprised.
        $this->saveDraftFromForm($this->form);

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

        // Capture migration context BEFORE deleteCurrentDraft() wipes it.
        $draft = $this->currentDraft();
        $draftPayload = is_array($draft?->payload ?? null) ? $draft->payload : [];
        $ploiSourceId = $draftPayload['_ploi_migration_source_id'] ?? null;
        $forgeSourceId = $draftPayload['_forge_migration_source_id'] ?? null;
        $ploiSelection = $draftPayload['_ploi_migration_site_selection'] ?? null;
        $forgeSelection = $draftPayload['_forge_migration_site_selection'] ?? null;

        try {
            $server = StoreServerFromCreateForm::run($user, $org, $this->form);
        } catch (ValidationException $e) {
            $this->mergeValidationException($e);

            return null;
        }

        // Kick off migration orchestration if the user entered via /imports/{source}.
        // Failures here don't abort server creation; the migration just doesn't
        // start automatically — they can retry from the inventory page.
        $migration = null;
        $migrationSourceLabel = null;
        if (is_string($ploiSourceId) && $ploiSourceId !== '') {
            $migration = $this->kickOffPloiMigration($ploiSourceId, $server, $user, $ploiSelection);
            $migrationSourceLabel = 'Ploi';
        } elseif (is_string($forgeSourceId) && $forgeSourceId !== '') {
            $migration = $this->kickOffForgeMigration($forgeSourceId, $server, $user, $forgeSelection);
            $migrationSourceLabel = 'Forge';
        }

        $this->deleteCurrentDraft();
        $this->flashSuccessForServerType($this->form->type);

        if ($migration !== null) {
            Session::flash('success', __('Server is being created. We will start migrating your :source sites once it is ready.', [
                'source' => $migrationSourceLabel,
            ]));

            $inventoryRoute = $migration->source === 'forge'
                ? 'imports.forge.inventory'
                : 'imports.ploi.inventory';

            return $this->redirect(route($inventoryRoute), navigate: true);
        }

        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    /**
     * Build the ImportServerMigration plan and dispatch the first runnable step.
     * Defaults to all v1-eligible (laravel/php, not removed_from_source) sites on
     * the source server — site-selection UI lands in a follow-up phase.
     */
    /**
     * @param  array<string, bool>|null  $explicitSelection  Site ulid → selected map
     *         from the wizard. Null falls back to all-eligible-sites default.
     */
    protected function kickOffPloiMigration(string $migrationSourceId, Server $server, $user, ?array $explicitSelection = null): ?ImportServerMigration
    {
        try {
            $org = $user->currentOrganization();
            if ($org === null) {
                return null;
            }
            // Q18: migrate-import is restricted to owners + admins; deployers
            // can create servers but not start migrations on them.
            if (! $org->hasAdminAccess($user)) {
                Log::info('Ploi migration kickoff blocked by role gate', [
                    'user_id' => $user->getKey(),
                    'org_id' => $org->getKey(),
                ]);

                return null;
            }

            $ploiServer = PloiServer::query()
                ->with('providerCredential')
                ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
                ->find($migrationSourceId);

            if ($ploiServer === null || $ploiServer->providerCredential === null) {
                return null;
            }

            $eligibleSiteIds = PloiSite::query()
                ->where('ploi_server_id', $ploiServer->id)
                ->where('removed_from_source', false)
                ->whereIn('site_type', ['laravel', 'php'])
                ->pluck('id')
                ->all();

            // Apply the wizard's explicit selection if present; intersect with
            // the eligibility-filtered set so a user can't override an
            // unsupported site into the plan.
            if (is_array($explicitSelection)) {
                $selectedSiteIds = array_values(array_filter(
                    $eligibleSiteIds,
                    fn (string $id): bool => ($explicitSelection[$id] ?? false) === true,
                ));
            } else {
                $selectedSiteIds = $eligibleSiteIds;
            }

            if ($selectedSiteIds === []) {
                Log::info('Ploi migration kickoff: no eligible sites on source server', [
                    'ploi_server_id' => $ploiServer->id,
                ]);

                return null;
            }

            $migration = (new MigrationPlanner())->plan(
                source: $ploiServer,
                selectedSiteIds: $selectedSiteIds,
                targetServerId: $server->id,
                credential: $ploiServer->providerCredential,
                userId: $user->getKey(),
            );

            $firstStep = $migration->steps()->first();
            if ($firstStep !== null) {
                RunMigrationStepJob::dispatch($firstStep->id);
            }

            return $migration;
        } catch (\Throwable $e) {
            Log::warning('Ploi migration kickoff failed; server-create succeeded', [
                'migration_source_id' => $migrationSourceId,
                'target_server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Forge analogue of kickOffPloiMigration — same shape, ForgeServer/ForgeSite.
     *
     * @param  array<string, bool>|null  $explicitSelection
     */
    protected function kickOffForgeMigration(string $migrationSourceId, Server $server, $user, ?array $explicitSelection = null): ?ImportServerMigration
    {
        try {
            $org = $user->currentOrganization();
            if ($org === null) {
                return null;
            }
            if (! $org->hasAdminAccess($user)) {
                Log::info('Forge migration kickoff blocked by role gate', [
                    'user_id' => $user->getKey(),
                    'org_id' => $org->getKey(),
                ]);

                return null;
            }

            $forgeServer = \App\Models\ForgeServer::query()
                ->with('providerCredential')
                ->whereHas('providerCredential', fn ($q) => $q->where('organization_id', $org->getKey()))
                ->find($migrationSourceId);

            if ($forgeServer === null || $forgeServer->providerCredential === null) {
                return null;
            }

            $eligibleSiteIds = \App\Models\ForgeSite::query()
                ->where('forge_server_id', $forgeServer->id)
                ->where('removed_from_source', false)
                ->whereIn('site_type', ['laravel', 'php'])
                ->pluck('id')
                ->all();

            if (is_array($explicitSelection)) {
                $selectedSiteIds = array_values(array_filter(
                    $eligibleSiteIds,
                    fn (string $id): bool => ($explicitSelection[$id] ?? false) === true,
                ));
            } else {
                $selectedSiteIds = $eligibleSiteIds;
            }

            if ($selectedSiteIds === []) {
                Log::info('Forge migration kickoff: no eligible sites on source server', [
                    'forge_server_id' => $forgeServer->id,
                ]);

                return null;
            }

            $migration = (new MigrationPlanner())->plan(
                source: $forgeServer,
                selectedSiteIds: $selectedSiteIds,
                targetServerId: $server->id,
                credential: $forgeServer->providerCredential,
                userId: $user->getKey(),
            );

            $firstStep = $migration->steps()->first();
            if ($firstStep !== null) {
                RunMigrationStepJob::dispatch($firstStep->id);
            }

            return $migration;
        } catch (\Throwable $e) {
            Log::warning('Forge migration kickoff failed; server-create succeeded', [
                'migration_source_id' => $migrationSourceId,
                'target_server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function flashSuccessForServerType(string $type): void
    {
        Session::flash('success', match ($type) {
            'digitalocean_functions' => __('DigitalOcean Functions host added. Create a site to wire its runtime and deploy settings.'),
            'aws_lambda' => __('AWS Lambda target added. Create a site to wire its runtime and Bref deploy settings.'),
            'digitalocean_kubernetes' => __('DigitalOcean Kubernetes target added. Create a site to prepare manifests and cluster runtime settings.'),
            'equinix_metal' => __('Bare metal can take 5–10 minutes.'),
            'fly_io' => __('Fly.io machine is being created.'),
            'aws' => __('AWS EC2 instance is being created. This usually takes 1–2 minutes.'),
            'custom' => __('Server added.'),
            default => __('Server is being created. This usually takes 1–2 minutes.'),
        });
    }

    protected function stepNumber(): int
    {
        return 4;
    }

    /**
     * Live-refresh the preflight panel when the SSH-key modal
     * (which is a sibling Livewire component) reports a new key
     * was saved. Without this, the "Add a personal profile SSH
     * key" blocker stays red until the operator manually reloads
     * the page even though the key now exists.
     */
    #[On('personal-ssh-key-created')]
    public function refreshAfterPersonalSshKey(): void
    {
        // Touch the form so render() recomputes the preflight
        // context with the freshly-saved key visible. The actual
        // recompute happens in render(); no other state needs to
        // change.
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);

        $isVmShaped = ! ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker');

        return view('livewire.servers.create.step-review', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 4,
            'catalog' => $context['catalog'],
            'preflight' => $context['preflight'],
            'isVmShaped' => $isVmShaped,
        ]);
    }
}
