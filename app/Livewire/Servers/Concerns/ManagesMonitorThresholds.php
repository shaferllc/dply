<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesMonitorThresholds
{


    /**
     * Load threshold settings from server meta or fallback to config defaults.
     */
    protected function syncThresholdSettingsFromServer(): void
    {
        $meta = $this->server->meta ?? [];
        $thresholds = $meta['metric_thresholds'] ?? [];

        $this->thresholdCpu = isset($thresholds['cpu_warn_pct'])
            ? (float) $thresholds['cpu_warn_pct']
            : null;
        $this->thresholdMem = isset($thresholds['mem_warn_pct'])
            ? (float) $thresholds['mem_warn_pct']
            : null;
        $this->thresholdLoad = isset($thresholds['load_warn'])
            ? (float) $thresholds['load_warn']
            : null;
    }

    /**
     * Get effective thresholds (server override or config default).
     *
     * @return array{cpu: float, mem: float, load: float}
     */
    protected function effectiveThresholds(): array
    {
        return [
            'cpu' => $this->thresholdCpu ?? (float) config('insights.thresholds.cpu_warn_pct', 85),
            'mem' => $this->thresholdMem ?? (float) config('insights.thresholds.mem_warn_pct', 85),
            'load' => $this->thresholdLoad ?? (float) config('insights.thresholds.load_warn', 4.0),
        ];
    }

    /**
     * Enable threshold editing mode.
     */
    public function startEditingThresholds(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        // Initialize input values to current effective thresholds
        $effective = $this->effectiveThresholds();
        $this->thresholdCpuInput = $effective['cpu'];
        $this->thresholdMemInput = $effective['mem'];
        $this->thresholdLoadInput = $effective['load'];

        $this->editingThresholds = true;
    }

    /**
     * Cancel threshold editing without saving.
     */
    public function cancelEditingThresholds(): void
    {
        $this->editingThresholds = false;
        $this->resetErrorBag();
    }

    /**
     * Save threshold settings to server meta.
     */
    public function saveThresholdSettings(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'thresholdCpuInput' => ['required', 'numeric', 'min:1', 'max:99'],
            'thresholdMemInput' => ['required', 'numeric', 'min:1', 'max:99'],
            'thresholdLoadInput' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ], [], [
            'thresholdCpuInput' => __('CPU threshold'),
            'thresholdMemInput' => __('Memory threshold'),
            'thresholdLoadInput' => __('Load threshold'),
        ]);

        $meta = $this->server->meta ?? [];
        $meta['metric_thresholds'] = [
            'cpu_warn_pct' => round($this->thresholdCpuInput, 1),
            'mem_warn_pct' => round($this->thresholdMemInput, 1),
            'load_warn' => round($this->thresholdLoadInput, 2),
        ];

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncThresholdSettingsFromServer();
        $this->editingThresholds = false;
        $this->toastSuccess(__('Metric thresholds saved. KPI warning colors will update on the next sample.'));
    }

    /**
     * Clear server-specific thresholds and revert to config defaults.
     */
    public function resetThresholdsToDefaults(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $meta = $this->server->meta ?? [];
        unset($meta['metric_thresholds']);

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncThresholdSettingsFromServer();
        $this->editingThresholds = false;
        $this->toastSuccess(__('Reverted to organization defaults.'));
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }
}
