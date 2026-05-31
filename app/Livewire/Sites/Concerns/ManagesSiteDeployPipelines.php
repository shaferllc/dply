<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineSafetyPresets;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 * @property string $editingPipelineId
 */
trait ManagesSiteDeployPipelines
{
    #[Url(as: 'pipeline', except: '')]
    public string $editingPipelineId = '';

    public string $new_pipeline_name = '';

    public bool $duplicate_current_on_create = false;

    public bool $show_create_pipeline_form = false;

    public bool $show_apply_template_modal = false;

    public string $pending_template_key = '';

    public ?string $pending_delete_pipeline_id = null;

    public string $editing_pipeline_branches = '';

    public function setEditingPipeline(string $pipelineId): void
    {
        $this->authorize('view', $this->site);
        $pipeline = $this->site->deployPipelines()->whereKey($pipelineId)->first();
        if (! $pipeline) {
            return;
        }
        $this->editingPipelineId = (string) $pipeline->id;
        $this->syncEditingPipelineBranches();
        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
    }

    public function updatedEditingPipelineId(): void
    {
        $this->syncEditingPipelineBranches();
        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
    }

    public function openCreatePipelineForm(): void
    {
        $this->authorize('update', $this->site);
        $this->show_create_pipeline_form = true;
        $this->new_pipeline_name = '';
    }

    public function closeCreatePipelineForm(): void
    {
        $this->show_create_pipeline_form = false;
        $this->new_pipeline_name = '';
    }

    public function createDeployPipeline(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_pipeline_name' => 'required|string|max:120',
        ]);

        $manager = app(SiteDeployPipelineManager::class);
        $duplicateFrom = $this->duplicate_current_on_create ? $this->editingDeployPipeline()->id : null;
        $pipeline = $manager->createPipeline($this->site, $this->new_pipeline_name, $duplicateFrom);
        $this->editingPipelineId = (string) $pipeline->id;
        $this->syncEditingPipelineBranches();
        $this->duplicate_current_on_create = false;
        $this->closeCreatePipelineForm();
        $this->toastSuccess(__('Pipeline created.'));
    }

    public function duplicateEditingDeployPipeline(): void
    {
        $this->authorize('update', $this->site);
        $source = $this->editingDeployPipeline();
        $manager = app(SiteDeployPipelineManager::class);
        $pipeline = $manager->createPipeline(
            $this->site,
            $source->name.' '.__('(copy)'),
            $source->id,
        );
        $this->editingPipelineId = (string) $pipeline->id;
        $this->toastSuccess(__('Pipeline duplicated.'));
    }

    public function activateEditingDeployPipeline(): void
    {
        $this->authorize('update', $this->site);
        app(SiteDeployPipelineManager::class)->activatePipeline($this->site, $this->editingDeployPipeline());
        $this->site->refresh();
        $this->toastSuccess(__('This pipeline will run on the next deploy.'));
    }

    public function openDeletePipelineModal(string $pipelineId): void
    {
        $this->authorize('update', $this->site);
        $this->pending_delete_pipeline_id = $pipelineId;
    }

    public function closeDeletePipelineModal(): void
    {
        $this->pending_delete_pipeline_id = null;
    }

    public function confirmDeleteDeployPipeline(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->pending_delete_pipeline_id) {
            return;
        }
        $pipeline = $this->site->deployPipelines()->whereKey($this->pending_delete_pipeline_id)->first();
        $this->closeDeletePipelineModal();
        if (! $pipeline) {
            return;
        }
        try {
            app(SiteDeployPipelineManager::class)->deletePipeline($this->site, $pipeline);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }
        $this->site->refresh();
        if ($this->editingPipelineId === (string) $pipeline->id) {
            $this->editingPipelineId = (string) ($this->site->active_deploy_pipeline_id ?? '');
        }
        $this->toastSuccess(__('Pipeline deleted.'));
    }

    public function openApplyTemplateModal(string $templateKey): void
    {
        $this->authorize('update', $this->site);
        $this->pending_template_key = $templateKey;
        $this->show_apply_template_modal = true;
    }

    public function closeApplyTemplateModal(): void
    {
        $this->show_apply_template_modal = false;
        $this->pending_template_key = '';
    }

    public function confirmApplyDeployPipelineTemplate(): void
    {
        $this->authorize('update', $this->site);
        if ($this->pending_template_key === '') {
            return;
        }
        try {
            app(SiteDeployPipelineManager::class)->applyTemplate(
                $this->editingDeployPipeline(),
                $this->pending_template_key,
            );
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
            $this->closeApplyTemplateModal();

            return;
        }
        $this->closeApplyTemplateModal();
        $this->toastSuccess(__('Pipeline updated from template.'));
    }

    public function saveEditingPipelineBranches(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'editing_pipeline_branches' => 'nullable|string|max:500',
        ]);

        $pipeline = $this->editingDeployPipeline();
        $pipeline->update([
            'deploy_branches' => $this->parseDeployBranchesInput($this->editing_pipeline_branches),
        ]);
        $this->site->load('deployPipelines');
        $this->toastSuccess(__('Git branch mapping saved.'));
    }

    public function applyLaravelSafetyPresetBundle(): void
    {
        $this->authorize('update', $this->site);

        try {
            $result = app(DeployPipelineSafetyPresets::class)->apply(
                DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1,
                $this->editingDeployPipeline(),
                $this->site,
            );
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__(
            'Safety bundle applied (:hooks hook(s), :steps step(s) added).',
            ['hooks' => $result['hooks_added'], 'steps' => $result['steps_added']],
        ));
    }

    protected function syncEditingPipelineBranches(): void
    {
        $branches = $this->editingDeployPipeline()->deploy_branches ?? [];
        $this->editing_pipeline_branches = is_array($branches) && $branches !== []
            ? implode(', ', $branches)
            : '';
    }

    /**
     * @return list<string>
     */
    protected function parseDeployBranchesInput(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn (string $branch) => trim($branch))
            ->filter(fn (string $branch) => $branch !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $orderedStepIds
     */
    public function reorderDeployPipelineSteps(array $orderedStepIds): void
    {
        $this->authorize('update', $this->site);
        try {
            app(SiteDeployPipelineManager::class)->reorderSteps(
                $this->editingDeployPipeline(),
                $orderedStepIds,
            );
        } catch (\InvalidArgumentException) {
            return;
        }
    }

    protected function editingDeployPipeline(): SiteDeployPipeline
    {
        return app(SiteDeployPipelineManager::class)->resolveEditing(
            $this->site,
            $this->editingPipelineId !== '' ? $this->editingPipelineId : null,
        );
    }
}
