<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Services\Deploy\SiteDeployPipelineManager;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesPipelineAnchorScripts
{
    public string $pipeline_clone_script = '';

    public string $pipeline_activate_script = '';

    public bool $show_pipeline_anchor_form = false;

    /** @var 'clone'|'activate'|null */
    public ?string $editing_pipeline_anchor = null;

    public bool $pipeline_anchor_form_dirty = false;

    /** @var array{anchor: string, script: string}|null */
    public ?array $pipeline_anchor_form_snapshot = null;

    protected function pipelineAnchorModalName(): string
    {
        return 'pipeline-anchor-form';
    }

    public function openEditPipelineAnchor(string $anchor): void
    {
        $this->authorize('update', $this->site);
        if (! in_array($anchor, ['clone', 'activate'], true)) {
            return;
        }

        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineStepForm();
        $this->closeAddPipelineHookForm();
        $this->editing_pipeline_anchor = $anchor;
        $this->show_pipeline_anchor_form = true;
        $this->capturePipelineAnchorFormBaseline();
        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->pipelineAnchorModalName());
    }

    public function closePipelineAnchorForm(): void
    {
        $this->show_pipeline_anchor_form = false;
        $this->editing_pipeline_anchor = null;
        $this->clearPipelineAnchorFormDirtyState();
        $this->resetErrorBag();
        $this->dispatch('close-modal', $this->pipelineAnchorModalName());
    }

    public function savePipelineAnchorFromModal(): void
    {
        $this->authorize('update', $this->site);
        $this->savePipelineAnchorScripts();
        $this->toastSuccess(__('Pipeline script saved.'));
    }

    public function updatedPipelineCloneScript(): void
    {
        $this->refreshPipelineAnchorFormDirty();
    }

    public function updatedPipelineActivateScript(): void
    {
        $this->refreshPipelineAnchorFormDirty();
    }

    public function resetPipelineAnchorScriptsToDefaults(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_pipeline_anchor === 'clone') {
            $this->pipeline_clone_script = '';
        } elseif ($this->editing_pipeline_anchor === 'activate') {
            $this->pipeline_activate_script = '';
        }
        $this->refreshPipelineAnchorFormDirty();
    }

    protected function syncPipelineAnchorScriptsFromEditingPipeline(): void
    {
        $pipeline = $this->editingDeployPipeline();
        $this->pipeline_clone_script = (string) ($pipeline->clone_script ?? '');
        $this->pipeline_activate_script = (string) ($pipeline->activate_script ?? '');
    }

    protected function savePipelineAnchorScripts(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'pipeline_clone_script' => 'nullable|string|max:16000',
            'pipeline_activate_script' => 'nullable|string|max:16000',
        ]);

        app(SiteDeployPipelineManager::class)->updateAnchorScripts(
            $this->editingDeployPipeline(),
            $this->pipeline_clone_script,
            $this->pipeline_activate_script,
        );

        $this->syncPipelineAnchorScriptsFromEditingPipeline();
        $this->closePipelineAnchorForm();
    }

    protected function capturePipelineAnchorFormBaseline(): void
    {
        $script = $this->editing_pipeline_anchor === 'activate'
            ? trim($this->pipeline_activate_script)
            : trim($this->pipeline_clone_script);

        $this->pipeline_anchor_form_snapshot = [
            'anchor' => (string) $this->editing_pipeline_anchor,
            'script' => $script,
        ];
        $this->pipeline_anchor_form_dirty = false;
    }

    protected function refreshPipelineAnchorFormDirty(): void
    {
        if (! $this->show_pipeline_anchor_form || $this->pipeline_anchor_form_snapshot === null) {
            $this->pipeline_anchor_form_dirty = false;
            $this->syncPipelineFormEditsPending();

            return;
        }

        $current = $this->editing_pipeline_anchor === 'activate'
            ? trim($this->pipeline_activate_script)
            : trim($this->pipeline_clone_script);

        $this->pipeline_anchor_form_dirty = $current !== $this->pipeline_anchor_form_snapshot['script'];
        $this->syncPipelineFormEditsPending();
    }

    protected function clearPipelineAnchorFormDirtyState(): void
    {
        $this->pipeline_anchor_form_snapshot = null;
        $this->pipeline_anchor_form_dirty = false;
        $this->syncPipelineFormEditsPending();
    }
}
