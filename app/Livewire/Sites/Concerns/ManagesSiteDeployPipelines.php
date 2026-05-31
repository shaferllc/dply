<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;
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

    public function setEditingPipeline(string $pipelineId): void
    {
        $this->authorize('view', $this->site);
        $pipeline = $this->site->deployPipelines()->whereKey($pipelineId)->first();
        if (! $pipeline) {
            return;
        }
        $this->editingPipelineId = (string) $pipeline->id;
        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
    }

    public function updatedEditingPipelineId(): void
    {
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
