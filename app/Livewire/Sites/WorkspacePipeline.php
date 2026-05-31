<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Deploy\DeployPipelineTemplateCatalog;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Sites\PipelineAnchorScriptRunner;
use App\Support\Docs\ContextualDocResolver;
use App\Support\Sites\DeployPipelineAdvisor;
use App\Support\Sites\DeployPipelineIssueFixResolver;
use App\Support\Sites\DeployPipelinePalette;
use App\Support\Sites\DeployPipelineSafetyPresets;
use App\Support\Sites\DeployPipelineTimeline;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('layouts.app')]
class WorkspacePipeline extends Show
{
    use InteractsWithUnsavedChangesBar;

    #[Url(as: 'tab', except: 'overview')]
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

        $tab = request()->query('tab');
        $allowed = array_keys(config('site_deploy_pipeline.tabs', []));
        if (is_string($tab) && in_array($tab, $allowed, true)) {
            $this->pipelineTab = $tab;
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

        $updates = [
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
            'deploy_strategy' => $this->zero_downtime_enabled ? 'atomic' : 'simple',
        ];

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
        $this->site->load(['deployHooks', 'deployPipelines', 'activeDeployPipeline']);
        $editingPipeline = $this->editingDeployPipeline();
        $editingPipeline->load(['steps', 'hooks.notificationChannel']);

        $deploymentContract = app(DeploymentContractBuilder::class)->build($this->site);
        $deploymentPreflight = app(DeploymentPreflightValidator::class)->validate($this->site);
        $pipelineAdvisor = app(DeployPipelineAdvisor::class)->analyze($this->site, $editingPipeline);
        $pipelineAdvisorChecks = collect($pipelineAdvisor['checks']);
        $pipelineActionableChecks = DeployPipelineIssueFixResolver::actionableChecks(
            $this->site,
            $this->server,
            $pipelineAdvisorChecks,
        );

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
                'pipelineTimeline' => DeployPipelineTimeline::items($editingPipeline),
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
            ],
        ));
    }
}
