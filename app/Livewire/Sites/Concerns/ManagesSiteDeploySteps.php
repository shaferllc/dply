<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineStepDuplicate;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesSiteDeploySteps
{
    use ManagesPipelineAnchorScripts;
    use ManagesSiteDeployPipelines;

    public string $new_deploy_step_type = SiteDeployStep::TYPE_COMPOSER_INSTALL;

    public string $new_deploy_step_command = '';

    public int $new_deploy_step_timeout = 900;

    public string $new_deploy_step_phase = SiteDeployStep::PHASE_BUILD;

    public bool $show_pipeline_step_form = false;

    public ?string $editing_deploy_step_id = null;

    public ?string $palette_drop_step_type = null;

    public bool $show_duplicate_pipeline_step_modal = false;

    public string $pending_duplicate_step_type = '';

    public string $pending_duplicate_step_command = '';

    public int $pending_duplicate_step_timeout = 900;

    public ?int $pending_duplicate_insert_index = null;

    public string $pending_duplicate_step_phase = SiteDeployStep::PHASE_BUILD;

    /** @var 'palette'|'form'|'edit' */
    public string $pending_duplicate_step_source = 'palette';

    public ?string $pending_duplicate_existing_label = null;

    public ?string $pending_duplicate_editing_step_id = null;

    /** Whether the open add/edit step form differs from when it was opened. */
    public bool $pipeline_step_form_dirty = false;

    /** Drives the unsaved bar while step/anchor modals have pending edits (see unsaved-changes-bar formPendingWire). */
    public bool $pipeline_form_edits_pending = false;

    /**
     * Snapshot when the step form opened (public so it survives Livewire round-trips).
     *
     * @var array{type: string, command: string, timeout: int, phase: string}|null
     */
    public ?array $pipeline_step_form_snapshot = null;

    protected function pipelineStepModalName(): string
    {
        return 'pipeline-step-form';
    }

    public function openAddPipelineStepForm(?string $stepType = null, ?string $phase = null): void
    {
        $this->authorize('update', $this->site);
        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
        $this->editing_deploy_step_id = null;
        $this->new_deploy_step_type = $stepType ?? SiteDeployStep::TYPE_COMPOSER_INSTALL;
        $this->new_deploy_step_command = '';
        $this->new_deploy_step_timeout = 900;
        $this->new_deploy_step_phase = $phase ?? SiteDeployStep::defaultPhaseFor($this->new_deploy_step_type);
        $this->show_pipeline_step_form = true;
        $this->capturePipelineStepFormBaseline();
        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->pipelineStepModalName());
    }

    public function openEditPipelineStep(string $id): void
    {
        $this->authorize('update', $this->site);
        $step = $this->findPipelineStep($id);
        if (! $step) {
            return;
        }

        $this->closePipelineAnchorForm();
        $this->closeAddPipelineHookForm();
        $this->editing_deploy_step_id = (string) $id;
        $this->new_deploy_step_type = $step->step_type;
        $this->new_deploy_step_command = (string) ($step->custom_command ?? '');
        $this->new_deploy_step_timeout = (int) ($step->timeout_seconds ?? 900);
        $this->new_deploy_step_phase = $step->phase ?? SiteDeployStep::PHASE_BUILD;
        $this->show_pipeline_step_form = true;
        $this->capturePipelineStepFormBaseline();
        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->pipelineStepModalName());
    }

    public function closePipelineStepForm(): void
    {
        $this->show_pipeline_step_form = false;
        $this->editing_deploy_step_id = null;
        $this->new_deploy_step_command = '';
        $this->new_deploy_step_timeout = 900;
        $this->new_deploy_step_phase = SiteDeployStep::PHASE_BUILD;
        $this->clearPipelineStepFormDirtyState();
        $this->resetErrorBag();
        $this->dispatch('close-modal', $this->pipelineStepModalName());
    }

    /** @deprecated Use closePipelineStepForm */
    public function closeAddPipelineStepForm(): void
    {
        $this->closePipelineStepForm();
    }

    public function updatedNewDeployStepType(): void
    {
        if ($this->editing_deploy_step_id === null) {
            $this->new_deploy_step_phase = SiteDeployStep::defaultPhaseFor($this->new_deploy_step_type);
        }
        if (! SiteDeployStep::needsCustomCommand($this->new_deploy_step_type)) {
            $this->new_deploy_step_command = '';
        }
        $this->refreshPipelineStepFormDirty();
    }

    public function updatedNewDeployStepCommand(): void
    {
        $this->refreshPipelineStepFormDirty();
    }

    public function updatedNewDeployStepTimeout(): void
    {
        $this->refreshPipelineStepFormDirty();
    }

    public function updatedNewDeployStepPhase(): void
    {
        $this->refreshPipelineStepFormDirty();
    }

    public function saveDeployPipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $this->saveOpenPipelineStepFromWorkspace();
    }

    /**
     * Persist the open add/edit step form. No-op when the form is closed.
     * Returns true when the step was saved and the form closed.
     */
    protected function saveOpenPipelineStepFromWorkspace(): bool
    {
        if (! $this->show_pipeline_step_form) {
            return false;
        }

        if ($this->editing_deploy_step_id !== null) {
            $this->updateDeployPipelineStep();
        } else {
            $this->addDeployPipelineStep();
        }

        return ! $this->show_pipeline_step_form;
    }

    public function addDeployPipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $command = $this->validatedPipelineStepCommand();
        if ($command === false) {
            return;
        }

        $pipeline = $this->editingDeployPipeline();

        if ($this->shouldConfirmDuplicatePipelineStep($pipeline, $this->new_deploy_step_type, $command)) {
            $this->openDuplicatePipelineStepModal(
                stepType: $this->new_deploy_step_type,
                customCommand: $command,
                timeout: $this->new_deploy_step_timeout,
                insertIndex: null,
                phase: $this->new_deploy_step_phase,
                source: 'form',
            );

            return;
        }

        $this->persistPipelineStep(
            $pipeline,
            $this->new_deploy_step_type,
            $command,
            $this->new_deploy_step_timeout,
            null,
            $this->new_deploy_step_phase,
        );
        $this->closePipelineStepForm();
        $this->toastSuccess(__('Pipeline step added.'));
    }

    public function updateDeployPipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $step = $this->findPipelineStep($this->editing_deploy_step_id);
        if (! $step) {
            return;
        }

        $command = $this->validatedPipelineStepCommand();
        if ($command === false) {
            return;
        }

        $pipeline = $this->editingDeployPipeline();

        if ($this->shouldConfirmDuplicatePipelineStep(
            $pipeline,
            $this->new_deploy_step_type,
            $command,
            (string) $step->id,
        )) {
            $this->openDuplicatePipelineStepModal(
                stepType: $this->new_deploy_step_type,
                customCommand: $command,
                timeout: $this->new_deploy_step_timeout,
                insertIndex: null,
                phase: $this->new_deploy_step_phase,
                source: 'edit',
                editingStepId: (string) $step->id,
            );

            return;
        }

        app(SiteDeployPipelineManager::class)->updateStep(
            $pipeline,
            $step,
            $this->new_deploy_step_type,
            $command,
            $this->new_deploy_step_timeout,
            $this->new_deploy_step_phase,
        );

        $this->closePipelineStepForm();
        $this->toastSuccess(__('Pipeline step saved.'));
    }

    protected function capturePipelineStepFormBaseline(): void
    {
        $this->pipeline_step_form_snapshot = [
            'type' => $this->new_deploy_step_type,
            'command' => trim($this->new_deploy_step_command),
            'timeout' => $this->new_deploy_step_timeout,
            'phase' => $this->new_deploy_step_phase,
        ];
        $this->pipeline_step_form_dirty = false;
    }

    protected function refreshPipelineStepFormDirty(): void
    {
        if (! $this->show_pipeline_step_form || $this->pipeline_step_form_snapshot === null) {
            $this->pipeline_step_form_dirty = false;
            $this->syncPipelineFormEditsPending();

            return;
        }

        $this->pipeline_step_form_dirty =
            $this->new_deploy_step_type !== $this->pipeline_step_form_snapshot['type']
            || trim($this->new_deploy_step_command) !== $this->pipeline_step_form_snapshot['command']
            || $this->new_deploy_step_timeout !== $this->pipeline_step_form_snapshot['timeout']
            || $this->new_deploy_step_phase !== $this->pipeline_step_form_snapshot['phase'];

        $this->syncPipelineFormEditsPending();
    }

    protected function clearPipelineStepFormDirtyState(): void
    {
        $this->pipeline_step_form_snapshot = null;
        $this->pipeline_step_form_dirty = false;
        $this->syncPipelineFormEditsPending();
    }

    protected function syncPipelineFormEditsPending(): void
    {
        $this->pipeline_form_edits_pending = $this->pipeline_step_form_dirty
            || $this->pipeline_anchor_form_dirty;
    }

    public function addDeployPipelineStepFromPalette(
        string $stepType,
        ?int $insertIndex = null,
        string $phase = SiteDeployStep::PHASE_BUILD,
    ): void {
        $this->authorize('update', $this->site);
        $types = array_keys(SiteDeployStep::typeLabels());
        if (! in_array($stepType, $types, true)) {
            return;
        }

        if (! in_array($phase, SiteDeployStep::userPhases(), true)) {
            $phase = SiteDeployStep::defaultPhaseFor($stepType);
        }

        if (SiteDeployStep::needsCustomCommand($stepType)) {
            $this->openAddPipelineStepForm($stepType, $phase);

            return;
        }

        $pipeline = $this->editingDeployPipeline();

        if ($this->shouldConfirmDuplicatePipelineStep($pipeline, $stepType, null)) {
            $this->openDuplicatePipelineStepModal(
                stepType: $stepType,
                customCommand: null,
                timeout: 900,
                insertIndex: $insertIndex,
                phase: $phase,
                source: 'palette',
            );

            return;
        }

        $this->persistPipelineStep($pipeline, $stepType, null, 900, $insertIndex, $phase);
        $this->toastSuccess(__('Step added to pipeline.'));
    }

    public function closeDuplicatePipelineStepModal(): void
    {
        $this->show_duplicate_pipeline_step_modal = false;
        $this->pending_duplicate_step_type = '';
        $this->pending_duplicate_step_command = '';
        $this->pending_duplicate_step_timeout = 900;
        $this->pending_duplicate_insert_index = null;
        $this->pending_duplicate_step_phase = SiteDeployStep::PHASE_BUILD;
        $this->pending_duplicate_step_source = 'palette';
        $this->pending_duplicate_existing_label = null;
        $this->pending_duplicate_editing_step_id = null;
    }

    public function confirmAddDuplicatePipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $pipeline = $this->editingDeployPipeline();
        $command = trim($this->pending_duplicate_step_command) !== ''
            ? trim($this->pending_duplicate_step_command)
            : null;

        if ($this->pending_duplicate_step_source === 'edit' && $this->pending_duplicate_editing_step_id !== null) {
            $step = $this->findPipelineStep($this->pending_duplicate_editing_step_id);
            if ($step) {
                app(SiteDeployPipelineManager::class)->updateStep(
                    $pipeline,
                    $step,
                    $this->pending_duplicate_step_type,
                    $command,
                    $this->pending_duplicate_step_timeout,
                    $this->pending_duplicate_step_phase,
                );
            }
            $this->closePipelineStepForm();
            $this->toastSuccess(__('Pipeline step saved.'));
        } else {
            $this->persistPipelineStep(
                $pipeline,
                $this->pending_duplicate_step_type,
                $command,
                $this->pending_duplicate_step_timeout,
                $this->pending_duplicate_insert_index,
                $this->pending_duplicate_step_phase,
            );

            if ($this->pending_duplicate_step_source === 'form') {
                $this->closePipelineStepForm();
            }

            $this->toastSuccess(__('Pipeline step added.'));
        }

        $this->closeDuplicatePipelineStepModal();
    }

    /**
     * @param  list<string>  $orderedStepIds
     */
    public function reorderDeployPipelineBuildSteps(array $orderedStepIds): void
    {
        $this->authorize('update', $this->site);
        try {
            app(SiteDeployPipelineManager::class)->reorderBuildSteps(
                $this->editingDeployPipeline(),
                $orderedStepIds,
            );
        } catch (\InvalidArgumentException) {
            return;
        }
    }

    /**
     * @param  list<string>  $orderedStepIds
     */
    public function reorderDeployPipelineReleaseSteps(array $orderedStepIds): void
    {
        $this->authorize('update', $this->site);
        try {
            app(SiteDeployPipelineManager::class)->reorderReleaseSteps(
                $this->editingDeployPipeline(),
                $orderedStepIds,
            );
        } catch (\InvalidArgumentException) {
            return;
        }
    }

    public function deleteDeployPipelineStep(string $id): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_deploy_step_id === (string) $id) {
            $this->closePipelineStepForm();
        }
        SiteDeployStep::query()
            ->where('pipeline_id', $this->editingDeployPipeline()->id)
            ->whereKey($id)
            ->delete();
        $this->toastSuccess(__('Pipeline step removed.'));
    }

    public function moveDeployStepUp(string $id): void
    {
        $this->authorize('update', $this->site);
        $pipeline = $this->editingDeployPipeline();
        $ids = $pipeline->steps()->orderBy('sort_order')->pluck('id')->map(fn ($i) => (string) $i)->all();
        $pos = array_search((string) $id, $ids, true);
        if ($pos === false || $pos === 0) {
            return;
        }
        [$ids[$pos - 1], $ids[$pos]] = [$ids[$pos], $ids[$pos - 1]];
        app(SiteDeployPipelineManager::class)->reorderSteps($pipeline, $ids);
    }

    public function moveDeployStepDown(string $id): void
    {
        $this->authorize('update', $this->site);
        $pipeline = $this->editingDeployPipeline();
        $ids = $pipeline->steps()->orderBy('sort_order')->pluck('id')->map(fn ($i) => (string) $i)->all();
        $pos = array_search((string) $id, $ids, true);
        if ($pos === false || $pos >= count($ids) - 1) {
            return;
        }
        [$ids[$pos + 1], $ids[$pos]] = [$ids[$pos], $ids[$pos + 1]];
        app(SiteDeployPipelineManager::class)->reorderSteps($pipeline, $ids);
    }

    /**
     * @return false|string|null false when validation failed; null when command not used
     */
    protected function validatedPipelineStepCommand(): false|string|null
    {
        $types = array_keys(SiteDeployStep::typeLabels());
        $this->validate([
            'new_deploy_step_type' => 'required|string|in:'.implode(',', $types),
            'new_deploy_step_command' => 'nullable|string|max:4000',
            'new_deploy_step_timeout' => 'required|integer|min:30|max:3600',
            'new_deploy_step_phase' => 'required|string|in:'.implode(',', SiteDeployStep::userPhases()),
        ]);

        if (SiteDeployStep::needsCustomCommand($this->new_deploy_step_type)
            && trim($this->new_deploy_step_command) === '') {
            $this->addError('new_deploy_step_command', __('This step type needs a value in the command field.'));

            return false;
        }

        return trim($this->new_deploy_step_command) !== '' ? trim($this->new_deploy_step_command) : null;
    }

    protected function findPipelineStep(string $id): ?SiteDeployStep
    {
        return SiteDeployStep::query()
            ->where('pipeline_id', $this->editingDeployPipeline()->id)
            ->whereKey($id)
            ->first();
    }

    protected function shouldConfirmDuplicatePipelineStep(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand,
        ?string $exceptStepId = null,
    ): bool {
        return DeployPipelineStepDuplicate::find($pipeline, $stepType, $customCommand, $exceptStepId) !== null;
    }

    protected function openDuplicatePipelineStepModal(
        string $stepType,
        ?string $customCommand,
        int $timeout,
        ?int $insertIndex,
        string $phase,
        string $source,
        ?string $editingStepId = null,
    ): void {
        $existing = DeployPipelineStepDuplicate::find(
            $this->editingDeployPipeline(),
            $stepType,
            $customCommand,
            $editingStepId,
        );

        $this->pending_duplicate_step_type = $stepType;
        $this->pending_duplicate_step_command = $customCommand ?? '';
        $this->pending_duplicate_step_timeout = $timeout;
        $this->pending_duplicate_insert_index = $insertIndex;
        $this->pending_duplicate_step_phase = $phase;
        $this->pending_duplicate_step_source = $source;
        $this->pending_duplicate_editing_step_id = $editingStepId;
        $this->pending_duplicate_existing_label = $existing?->pillLabel();
        $this->show_duplicate_pipeline_step_modal = true;
    }

    protected function persistPipelineStep(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand,
        int $timeoutSeconds,
        ?int $insertIndex = null,
        ?string $phase = null,
    ): void {
        app(SiteDeployPipelineManager::class)->addStep(
            $pipeline,
            $stepType,
            $customCommand,
            $timeoutSeconds,
            $insertIndex,
            $phase,
        );
    }
}
