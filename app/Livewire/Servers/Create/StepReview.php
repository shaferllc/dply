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

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        if ($redirect = $this->enforceDraftGate()) {
            return $redirect;
        }

        $this->hydrateFormFromDraft($this->form, $this->currentDraft());

        return null;
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
        $migrationSourceId = is_array($draft?->payload ?? null)
            ? ($draft->payload['_ploi_migration_source_id'] ?? null)
            : null;

        try {
            $server = StoreServerFromCreateForm::run($user, $org, $this->form);
        } catch (ValidationException $e) {
            $this->mergeValidationException($e);

            return null;
        }

        // Kick off Ploi-migration orchestration if the user entered the wizard via
        // /imports/ploi → "Migrate this server". Failures here don't abort server
        // creation — the user still gets their server; the migration just doesn't
        // start automatically. They can retry from the inventory page.
        $migration = null;
        if (is_string($migrationSourceId) && $migrationSourceId !== '') {
            $migration = $this->kickOffPloiMigration($migrationSourceId, $server, $user);
        }

        $this->deleteCurrentDraft();
        $this->flashSuccessForServerType($this->form->type);

        if ($migration !== null) {
            Session::flash('success', __('Server is being created. We will start migrating your Ploi sites once it is ready.'));

            return $this->redirect(route('imports.ploi.inventory'), navigate: true);
        }

        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    /**
     * Build the ImportServerMigration plan and dispatch the first runnable step.
     * Defaults to all v1-eligible (laravel/php, not removed_from_source) sites on
     * the source server — site-selection UI lands in a follow-up phase.
     */
    protected function kickOffPloiMigration(string $migrationSourceId, Server $server, $user): ?ImportServerMigration
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

            $selectedSiteIds = PloiSite::query()
                ->where('ploi_server_id', $ploiServer->id)
                ->where('removed_from_source', false)
                ->whereIn('site_type', ['laravel', 'php'])
                ->pluck('id')
                ->all();

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
