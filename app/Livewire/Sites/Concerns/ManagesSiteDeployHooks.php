<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\SiteDeployHook;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesSiteDeployHooks
{
    public string $new_hook_kind = SiteDeployHook::KIND_SHELL;

    public string $new_hook_anchor = SiteDeployHook::ANCHOR_AFTER_CLONE;

    public string $new_hook_anchor_step_id = '';

    public string $new_hook_label = '';

    public string $new_hook_script = '';

    public string $new_hook_webhook_url = '';

    public string $new_hook_notification_channel_id = '';

    public string $new_hook_notification_event = SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;

    public int $new_hook_order = 0;

    public int $new_hook_timeout_seconds = 900;

    public bool $show_add_pipeline_hook_form = false;

    public ?string $editing_deploy_hook_id = null;

    /** When set, the hook form was opened from a timeline drop zone (anchor is fixed). */
    public bool $hook_form_anchor_locked = false;

    protected function pipelineHookModalName(): string
    {
        return 'pipeline-hook-form';
    }

    /**
     * @param  array{kind: string, label?: string, anchor?: string, script?: string}  $preset
     */
    public function addDeployPipelineHookFromPreset(array $preset): void
    {
        $kind = $preset['kind'] ?? SiteDeployHook::KIND_SHELL;
        $anchor = $preset['anchor'] ?? SiteDeployHook::ANCHOR_AFTER_ACTIVATE;
        $script = trim((string) ($preset['script'] ?? ''));

        $this->openAddPipelineHookForm($kind, $anchor, lockAnchor: isset($preset['anchor']));
        if ($kind === SiteDeployHook::KIND_SHELL && $script !== '') {
            $this->new_hook_script = $script;
        }
    }

    public function addDeployPipelineHookFromPalette(
        string $kind,
        string $anchor,
        ?string $anchorStepId = null,
    ): void {
        $this->authorize('update', $this->site);

        if (! in_array($kind, SiteDeployHook::kinds(), true)
            || ! in_array($anchor, SiteDeployHook::anchors(), true)) {
            return;
        }

        if ($anchor === SiteDeployHook::ANCHOR_AFTER_STEP) {
            if ($anchorStepId === null || $anchorStepId === '') {
                $this->toastError(__('Drop this hook on the slot after a build or release step.'));

                return;
            }
            $stepExists = $this->editingDeployPipeline()->steps()->whereKey($anchorStepId)->exists();
            if (! $stepExists) {
                $this->toastError(__('That step is no longer on this pipeline.'));

                return;
            }
        }

        $this->openAddPipelineHookForm($kind, $anchor, lockAnchor: true);
        if ($anchorStepId !== null && $anchorStepId !== '') {
            $this->new_hook_anchor_step_id = $anchorStepId;
        }
        if ($kind === SiteDeployHook::KIND_SHELL && trim($this->new_hook_script) === '') {
            $this->new_hook_script = "echo ok\n";
        }
    }

    public function openEditPipelineHook(string $id): void
    {
        $this->authorize('update', $this->site);
        $hook = SiteDeployHook::query()
            ->where('pipeline_id', $this->editingDeployPipeline()->id)
            ->whereKey($id)
            ->first();

        if (! $hook) {
            return;
        }

        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->editing_deploy_hook_id = (string) $hook->id;
        $this->show_add_pipeline_hook_form = true;
        $this->hook_form_anchor_locked = true;
        $this->populatePipelineHookFormFromModel($hook);
        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->pipelineHookModalName());
    }

    public function openAddPipelineHookForm(?string $kind = null, ?string $anchor = null, bool $lockAnchor = false): void
    {
        $this->authorize('update', $this->site);
        $this->closePipelineStepForm();
        $this->closePipelineAnchorForm();
        $this->editing_deploy_hook_id = null;
        $this->show_add_pipeline_hook_form = true;
        $this->hook_form_anchor_locked = $lockAnchor && $anchor !== null;
        if ($kind !== null && in_array($kind, SiteDeployHook::kinds(), true)) {
            $this->new_hook_kind = $kind;
        }
        if ($anchor !== null && in_array($anchor, SiteDeployHook::anchors(), true)) {
            $this->new_hook_anchor = $anchor;
        } elseif (! $lockAnchor) {
            $this->new_hook_anchor = SiteDeployHook::ANCHOR_AFTER_CLONE;
        }
        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->pipelineHookModalName());
    }

    public function closeAddPipelineHookForm(): void
    {
        $this->show_add_pipeline_hook_form = false;
        $this->editing_deploy_hook_id = null;
        $this->resetHookForm();
        $this->resetErrorBag();
        $this->dispatch('close-modal', $this->pipelineHookModalName());
    }

    public function updatedNewHookKind(): void
    {
        if ($this->new_hook_kind !== SiteDeployHook::KIND_SHELL) {
            $this->new_hook_script = '';
        }
        if ($this->new_hook_kind !== SiteDeployHook::KIND_WEBHOOK) {
            $this->new_hook_webhook_url = '';
        }
        if ($this->new_hook_kind !== SiteDeployHook::KIND_NOTIFICATION) {
            $this->new_hook_notification_channel_id = '';
        }
    }

    public function updatedNewHookAnchor(): void
    {
        if ($this->new_hook_anchor !== SiteDeployHook::ANCHOR_AFTER_STEP) {
            $this->new_hook_anchor_step_id = '';
        }
    }

    public function saveDeployPipelineHook(): void
    {
        if ($this->editing_deploy_hook_id !== null) {
            $this->updateDeployPipelineHook();

            return;
        }

        $this->addDeployPipelineHook();
    }

    public function addDeployPipelineHook(): void
    {
        $this->authorize('update', $this->site);
        $pipeline = $this->editingDeployPipeline();

        $rules = [
            'new_hook_kind' => 'required|in:'.implode(',', SiteDeployHook::kinds()),
            'new_hook_anchor' => 'required|in:'.implode(',', SiteDeployHook::anchors()),
            'new_hook_label' => 'nullable|string|max:120',
            'new_hook_order' => 'integer|min:0|max:999',
            'new_hook_timeout_seconds' => 'required|integer|min:30|max:3600',
        ];

        if ($this->new_hook_kind === SiteDeployHook::KIND_SHELL) {
            $rules['new_hook_script'] = 'required|string|max:16000';
        }
        if ($this->new_hook_kind === SiteDeployHook::KIND_WEBHOOK) {
            $rules['new_hook_webhook_url'] = 'required|url|max:2000';
        }
        if ($this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION) {
            $rules['new_hook_notification_channel_id'] = 'required|string';
            $rules['new_hook_notification_event'] = 'required|in:'.SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_STARTED.','.SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;
        }
        if ($this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP) {
            $rules['new_hook_anchor_step_id'] = 'required|string';
        }

        $this->validate($rules);

        if ($this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP) {
            $stepExists = $pipeline->steps()->whereKey($this->new_hook_anchor_step_id)->exists();
            if (! $stepExists) {
                $this->addError('new_hook_anchor_step_id', __('Pick a build step for this hook.'));

                return;
            }
        }

        $phase = match ($this->new_hook_anchor) {
            SiteDeployHook::ANCHOR_AFTER_STEP => SiteDeployHook::PHASE_AFTER_CLONE,
            SiteDeployHook::ANCHOR_BEFORE_ACTIVATE => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
            default => $this->new_hook_anchor,
        };

        SiteDeployHook::query()->create([
            'site_id' => $this->site->id,
            'pipeline_id' => $pipeline->id,
            'phase' => $phase,
            'hook_kind' => $this->new_hook_kind,
            'anchor' => $this->new_hook_anchor,
            'anchor_step_id' => $this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP
                ? $this->new_hook_anchor_step_id
                : null,
            'label' => trim($this->new_hook_label) !== '' ? trim($this->new_hook_label) : null,
            'script' => $this->new_hook_kind === SiteDeployHook::KIND_SHELL ? $this->new_hook_script : null,
            'webhook_url' => $this->new_hook_kind === SiteDeployHook::KIND_WEBHOOK ? $this->new_hook_webhook_url : null,
            'notification_channel_id' => $this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION
                ? $this->new_hook_notification_channel_id
                : null,
            'notification_event' => $this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION
                ? $this->new_hook_notification_event
                : null,
            'sort_order' => $this->new_hook_order,
            'timeout_seconds' => $this->new_hook_timeout_seconds,
        ]);

        $this->closeAddPipelineHookForm();
        $this->toastSuccess(__('Pipeline hook added.'));
    }

    public function updateDeployPipelineHook(): void
    {
        $this->authorize('update', $this->site);
        $pipeline = $this->editingDeployPipeline();
        $hook = SiteDeployHook::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereKey($this->editing_deploy_hook_id)
            ->first();

        if (! $hook) {
            $this->closeAddPipelineHookForm();

            return;
        }

        $rules = [
            'new_hook_kind' => 'required|in:'.implode(',', SiteDeployHook::kinds()),
            'new_hook_anchor' => 'required|in:'.implode(',', SiteDeployHook::anchors()),
            'new_hook_label' => 'nullable|string|max:120',
            'new_hook_order' => 'integer|min:0|max:999',
            'new_hook_timeout_seconds' => 'required|integer|min:30|max:3600',
        ];

        if ($this->new_hook_kind === SiteDeployHook::KIND_SHELL) {
            $rules['new_hook_script'] = 'required|string|max:16000';
        }
        if ($this->new_hook_kind === SiteDeployHook::KIND_WEBHOOK) {
            $rules['new_hook_webhook_url'] = 'required|url|max:2000';
        }
        if ($this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION) {
            $rules['new_hook_notification_channel_id'] = 'required|string';
            $rules['new_hook_notification_event'] = 'required|in:'.SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_STARTED.','.SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;
        }
        if ($this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP) {
            $rules['new_hook_anchor_step_id'] = 'required|string';
        }

        $this->validate($rules);

        if ($this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP) {
            $stepExists = $pipeline->steps()->whereKey($this->new_hook_anchor_step_id)->exists();
            if (! $stepExists) {
                $this->addError('new_hook_anchor_step_id', __('Pick a build step for this hook.'));

                return;
            }
        }

        $phase = match ($this->new_hook_anchor) {
            SiteDeployHook::ANCHOR_AFTER_STEP => SiteDeployHook::PHASE_AFTER_CLONE,
            SiteDeployHook::ANCHOR_BEFORE_ACTIVATE => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
            default => $this->new_hook_anchor,
        };

        $hook->update([
            'phase' => $phase,
            'hook_kind' => $this->new_hook_kind,
            'anchor' => $this->new_hook_anchor,
            'anchor_step_id' => $this->new_hook_anchor === SiteDeployHook::ANCHOR_AFTER_STEP
                ? $this->new_hook_anchor_step_id
                : null,
            'label' => trim($this->new_hook_label) !== '' ? trim($this->new_hook_label) : null,
            'script' => $this->new_hook_kind === SiteDeployHook::KIND_SHELL ? $this->new_hook_script : null,
            'webhook_url' => $this->new_hook_kind === SiteDeployHook::KIND_WEBHOOK ? $this->new_hook_webhook_url : null,
            'notification_channel_id' => $this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION
                ? $this->new_hook_notification_channel_id
                : null,
            'notification_event' => $this->new_hook_kind === SiteDeployHook::KIND_NOTIFICATION
                ? $this->new_hook_notification_event
                : null,
            'sort_order' => $this->new_hook_order,
            'timeout_seconds' => $this->new_hook_timeout_seconds,
        ]);

        $this->closeAddPipelineHookForm();
        $this->toastSuccess(__('Pipeline hook updated.'));
    }

    public function deleteDeployHook(string $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployHook::query()
            ->where('pipeline_id', $this->editingDeployPipeline()->id)
            ->whereKey($id)
            ->delete();
        $this->toastSuccess(__('Hook removed.'));
    }

    /** @return Collection<int, NotificationChannel> */
    protected function notificationChannelsForSite(): Collection
    {
        $org = $this->site->organization;

        return $org
            ? $org->notificationChannels()->orderBy('label')->get()
            : collect();
    }

    protected function populatePipelineHookFormFromModel(SiteDeployHook $hook): void
    {
        $this->new_hook_kind = $hook->hook_kind;
        $this->new_hook_anchor = $hook->anchor;
        $this->new_hook_anchor_step_id = (string) ($hook->anchor_step_id ?? '');
        $this->new_hook_label = (string) ($hook->label ?? '');
        $this->new_hook_script = (string) ($hook->script ?? '');
        $this->new_hook_webhook_url = (string) ($hook->webhook_url ?? '');
        $this->new_hook_notification_channel_id = (string) ($hook->notification_channel_id ?? '');
        $this->new_hook_notification_event = $hook->notification_event
            ?? SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;
        $this->new_hook_order = (int) ($hook->sort_order ?? 0);
        $this->new_hook_timeout_seconds = (int) ($hook->timeout_seconds ?? 900);
    }

    private function resetHookForm(): void
    {
        $this->hook_form_anchor_locked = false;
        $this->new_hook_kind = SiteDeployHook::KIND_SHELL;
        $this->new_hook_anchor = SiteDeployHook::ANCHOR_AFTER_CLONE;
        $this->new_hook_anchor_step_id = '';
        $this->new_hook_label = '';
        $this->new_hook_script = '';
        $this->new_hook_webhook_url = '';
        $this->new_hook_notification_channel_id = '';
        $this->new_hook_notification_event = SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;
        $this->new_hook_order = 0;
        $this->new_hook_timeout_seconds = 900;
    }
}
