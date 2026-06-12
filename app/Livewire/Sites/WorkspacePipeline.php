<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\DeploymentMethod;
use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\DeployPipelineTemplateCatalog;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Sites\PipelineAnchorScriptRunner;
use App\Support\Docs\ContextualDocResolver;
use App\Support\Sites\DeployPipelineAdvisor;
use App\Support\Sites\DeployPipelineIssueFixResolver;
use App\Support\Sites\DeployPipelinePalette;
use App\Support\Sites\DeployPipelineSafetyPresets;
use App\Support\Sites\DeployPipelineScriptExporter;
use App\Support\Sites\DeployPipelineStarterCatalog;
use App\Support\Sites\DeployPipelineTimeline;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('layouts.app')]
class WorkspacePipeline extends Show
{
    use InteractsWithUnsavedChangesBar;

    /** Suppress the page wrapper (breadcrumb / sidebar / header) when rendered
     * inside another page (Deployments → Settings tab). */
    public bool $embedded = false;

    /** When set, pin the pipeline to a single sub-tab (steps / rollout / etc.)
     * and hide the internal tablist. Used by Deployments' top-level Pipeline
     * and Rollout tabs. */
    public string $lockedTab = '';

    // Aliased to `pipeline_tab` so this component plays nicely when embedded
    // inside the Deployments page (its own ?tab= owns the outer tab strip).
    #[Url(as: 'pipeline_tab', except: 'overview')]
    public string $pipelineTab = 'overview';

    public function mount(Server $server, Site $site): void
    {
        if ($site->usesEdgeRuntime()) {
            abort(404);
        }

        parent::mount($server, $site);

        app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
        $this->syncEditingPipelineBranches();
        $this->syncPipelineAnchorScriptsFromEditingPipeline();

        $allowed = array_keys(config('site_deploy_pipeline.tabs', []));

        // When embedded inside another page (e.g. DeploymentsList), Livewire's
        // #[Url] attribute may not initialise from the request URL before mount()
        // runs for nested components. Read pipeline_tab explicitly so a direct
        // link like ?tab=pipeline&pipeline_tab=steps lands on the right sub-tab.
        $pipelineTab = request()->query('pipeline_tab');
        if (is_string($pipelineTab) && in_array($pipelineTab, $allowed, true)) {
            $this->pipelineTab = $pipelineTab;
        }

        // Fallback: ?tab=<pipeline-subtab> (standalone page usage without pipeline_tab prefix).
        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, $allowed, true)) {
            $this->pipelineTab = $tab;
        }

        if ($this->lockedTab !== '' && in_array($this->lockedTab, $allowed, true)) {
            $this->pipelineTab = $this->lockedTab;
        }
    }

    public function setPipelineTab(string $tab): void
    {
        $allowed = array_keys(config('site_deploy_pipeline.tabs', []));
        $this->pipelineTab = in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    public function savePostDeployCommand(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'post_deploy_command' => 'nullable|string|max:4000',
        ]);
        $this->site->update([
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ]);
        $this->syncFormFromSite();
        $this->toastSuccess(__('Post-deploy command saved.'));
    }

    public function discardPipelineWorkspaceUnsaved(): void
    {
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
    }

    public function savePipelineWorkspace(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'post_deploy_command' => 'nullable|string|max:4000',
            'zero_downtime_enabled' => 'boolean',
        ]);

        $previousStrategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $newStrategy = $this->zero_downtime_enabled ? 'atomic' : 'simple';

        $updates = [
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
            'deploy_strategy' => $newStrategy,
        ];

        // Keep deploy_method aligned to the flat/atomic flip, but only when the
        // strategy actually changes — preserves a maintenance/recreate choice
        // across saves that didn't touch the zero-downtime toggle.
        if ($previousStrategy !== $newStrategy) {
            $updates['deploy_method'] = DeploymentMethod::fromStrategy($newStrategy)->value;
        }

        if (ephemeral_deploy_credentials_active($this->site->organization)) {
            $this->validate(['ephemeral_deploy_credentials_enabled' => 'boolean']);
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            data_set($meta, 'deploy.ephemeral_credentials', $this->ephemeral_deploy_credentials_enabled);
            $updates['meta'] = $meta;
        }

        $this->site->update($updates);
        $this->site->refresh();
        $this->deploy_strategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $this->zero_downtime_enabled = $this->deploy_strategy === 'atomic';

        $this->savePipelineAnchorScripts();
        $this->saveOpenPipelineStepFromWorkspace();
        $this->saveDeploymentSettings();
    }

    /**
     * Switch the site to atomic (zero-downtime) deploys in place. Wired from the
     * Pipeline review "Enable zero downtime" fix so the simple-deploy-migrations
     * warning resolves without sending the user to the Rollout tab.
     */
    public function enableZeroDowntimeDeploys(): void
    {
        $this->authorize('update', $this->site);

        $previousStrategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $updates = ['deploy_strategy' => 'atomic'];
        // Only realign deploy_method when coming from flat; if already atomic the
        // site may be on maintenance/recreate and we must not downgrade it.
        if ($previousStrategy !== 'atomic') {
            $updates['deploy_method'] = DeploymentMethod::Atomic->value;
        }

        $this->site->update($updates);
        $this->site->refresh();
        $this->deploy_strategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $this->zero_downtime_enabled = $this->deploy_strategy === 'atomic';

        $this->toastSuccess(__('Zero-downtime (atomic) deploys enabled.'));
    }

    /**
     * @return list<string>
     */
    protected function pipelineUnsavedTargetFields(): array
    {
        $fields = [
            'post_deploy_command',
            'zero_downtime_enabled',
            'releases_to_keep',
            'deployment_environment',
            'laravel_scheduler',
            'restart_supervisor_programs_after_deploy',
            'nginx_extra_raw',
            'deploy_health_enabled',
            'deploy_health_auto_rollback',
            'deploy_health_scheme',
            'deploy_health_host',
            'deploy_health_port',
            'deploy_health_path',
            'deploy_health_expect_status',
            'deploy_health_attempts',
            'deploy_health_delay_ms',
        ];

        if (ephemeral_deploy_credentials_active($this->site->organization)) {
            $fields[] = 'ephemeral_deploy_credentials_enabled';
        }

        $fields[] = 'pipeline_clone_script';
        $fields[] = 'pipeline_activate_script';

        if ($this->show_pipeline_step_form) {
            $fields[] = 'new_deploy_step_type';
            $fields[] = 'new_deploy_step_command';
            $fields[] = 'new_deploy_step_timeout';
            $fields[] = 'new_deploy_step_phase';
        }

        return $fields;
    }

    public function render(): View
    {
        $this->site->loadMissing(['deployHooks', 'previewDomains', 'certificates']);
        $editingPipeline = $this->editingDeployPipeline()->loadMissing(['steps', 'hooks']);

        $tab = $this->pipelineTab;

        // The deployment contract + preflight are display data for deploy-config
        // surfaces and are NOT read anywhere in the pipeline view tree (verified).
        // The builder eager-loads relations and runs the secret / resource-binding
        // resolvers, and the validator piles on — pure waste on every sub-tab
        // switch, so skip both. SiteSettingsViewData::for() handles the nulls.
        $deploymentContract = null;
        $deploymentPreflight = [];

        // The advisor (DB-backed) only feeds the Overview + Pipeline (steps) tabs;
        // Rollout / Reference never read its output. Skip it (and the actionable-
        // check resolution it drives) on those tabs.
        $needsAdvisor = in_array($tab, ['overview', 'steps'], true);
        $pipelineAdvisor = $needsAdvisor
            ? app(DeployPipelineAdvisor::class)->analyze($this->site, $editingPipeline)
            : ['checks' => [], 'errors' => [], 'warnings' => []];
        $pipelineAdvisorChecks = collect($pipelineAdvisor['checks']);
        $pipelineActionableChecks = $needsAdvisor
            ? DeployPipelineIssueFixResolver::actionableChecks($this->site, $this->server, $pipelineAdvisorChecks)
            : collect();

        // The bash export (DB-backed) only feeds the share modal on the Pipeline
        // (steps) tab; no other sub-tab renders it.
        $needsBashExport = $tab === 'steps';
        $pipelineBashFull = $needsBashExport ? app(DeployPipelineScriptExporter::class)->toFullBash($editingPipeline) : '';
        $pipelineBashCommands = $needsBashExport ? app(DeployPipelineScriptExporter::class)->toCommandsOnly($editingPipeline) : '';

        return view('livewire.sites.workspace-pipeline', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'pipeline',
                $deploymentContract,
                $deploymentPreflight,
                auth()->user(),
            ),
            [
                'section' => 'pipeline',
                'routingTab' => 'domains',
                'laravel_tab' => 'commands',
                'pipelineTabs' => config('site_deploy_pipeline.tabs', []),
                'pipelineTabIcons' => config('site_deploy_pipeline.tab_icons', []),
                'pipelinePalette' => DeployPipelinePalette::stepsFor($this->site),
                'pipelineHookPresets' => DeployPipelinePalette::hookPresetsFor($this->site),
                'pipelineStepCatalog' => DeployPipelinePalette::stepCatalogFor($this->site),
                'pipelineStepTypeReference' => DeployPipelinePalette::stepTypeReference(),
                'pipelineHookCatalog' => DeployPipelinePalette::hookCatalogFor($this->site),
                'deployPipelineTemplates' => app(DeployPipelineTemplateCatalog::class)->templatesForSite($this->site),
                'editingDeployPipeline' => $editingPipeline,
                'editingDeploySteps' => $editingPipeline->steps,
                'pipelineTimelineSplit' => DeployPipelineTimeline::splitForUi($editingPipeline),
                'notificationChannels' => $this->notificationChannelsForSite(),
                'deployHookAnchors' => SiteDeployHook::anchorLabels(),
                'deployHookKinds' => SiteDeployHook::kindLabels(),
                'contextualDocSlug' => app(ContextualDocResolver::class)->resolveForSiteSection($this->site, 'pipeline'),
                'pipelineUnsavedTargets' => implode(',', $this->pipelineUnsavedTargetFields()),
                'pipelineAnchorDefaultCloneHint' => app(PipelineAnchorScriptRunner::class)->defaultCloneScriptHint($this->site),
                'pipelineAnchorDefaultActivateHint' => app(PipelineAnchorScriptRunner::class)->defaultActivateScriptHint($this->site),
                'pipelineAdvisor' => $pipelineAdvisor,
                'pipelineAdvisorErrors' => collect($pipelineAdvisor['errors']),
                'pipelineAdvisorWarnings' => collect($pipelineAdvisor['warnings']),
                'pipelineActionableChecks' => $pipelineActionableChecks,
                'pipelineSafetyBundles' => DeployPipelineSafetyPresets::bundles(),
                'pipelineSafetyBundleVisible' => DeployPipelineSafetyPresets::visibleForSite(
                    $this->site,
                    DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1,
                ),
                'pipelineStarters' => app(DeployPipelineStarterCatalog::class)->startersForSite($this->site),
                'pipelineBashFull' => $pipelineBashFull,
                'pipelineBashCommands' => $pipelineBashCommands,
                'pipelineOverviewStepCount' => $editingPipeline->steps->count(),
                'pipelineOverviewHookCount' => $editingPipeline->hooks->count(),
                'pipelineOverviewName' => $editingPipeline->name,
                'pipelineOverviewIsActive' => $editingPipeline->isActiveFor($this->site),
            ],
        ));
    }
}
