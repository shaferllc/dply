<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Modules\Deploy\Console\RunDueDeploymentSchedulesCommand;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentSchedule;
use App\Services\Servers\CronExpressionValidator;
use Illuminate\Support\Facades\Cache;

/**
 * Create / toggle / delete recurring scheduled deploys for the site, plus a
 * "run now" shortcut. Backed by {@see SiteDeploymentSchedule}; the control-plane
 * scheduler ({@see RunDueDeploymentSchedulesCommand})
 * dispatches the deploy when a schedule is due.
 *
 * Requires DispatchesToastNotifications on the host component.
 */
trait ManagesSiteDeploymentSchedules
{
    public bool $show_add_schedule_form = false;

    public string $new_schedule_preset = 'daily';

    public string $new_schedule_cron = '0 3 * * *';

    public bool $new_schedule_notify = true;

    /**
     * Friendly cadence presets → cron expression. "custom" lets the operator
     * type any 5-field cron string.
     *
     * @return array<string, array{label: string, cron: string|null}>
     */
    public function scheduleCronPresets(): array
    {
        return [
            'every_15m' => ['label' => __('Every 15 minutes'), 'cron' => '*/15 * * * *'],
            'hourly' => ['label' => __('Hourly'), 'cron' => '0 * * * *'],
            'daily' => ['label' => __('Daily at 03:00'), 'cron' => '0 3 * * *'],
            'weekly' => ['label' => __('Weekly (Mon 03:00)'), 'cron' => '0 3 * * 1'],
            'custom' => ['label' => __('Custom…'), 'cron' => null],
        ];
    }

    public function updatedNewSchedulePreset(string $value): void
    {
        $preset = $this->scheduleCronPresets()[$value] ?? null;
        if ($preset && $preset['cron'] !== null) {
            $this->new_schedule_cron = $preset['cron'];
        }
    }

    public function openAddScheduleForm(): void
    {
        $this->authorize('update', $this->site);
        $this->show_add_schedule_form = true;
    }

    public function closeAddScheduleForm(): void
    {
        $this->show_add_schedule_form = false;
        $this->resetErrorBag(['new_schedule_cron']);
    }

    public function addDeploymentSchedule(CronExpressionValidator $validator): void
    {
        $this->authorize('update', $this->site);

        $cron = trim($this->new_schedule_cron);
        if (! $validator->isValid($cron)) {
            $this->addError('new_schedule_cron', __('Enter a valid 5-field cron expression (e.g. 0 3 * * *).'));

            return;
        }

        SiteDeploymentSchedule::create([
            'site_id' => $this->site->id,
            'server_id' => $this->site->server_id,
            'cron_expression' => $cron,
            'timezone' => config('app.timezone', 'UTC'),
            'is_active' => true,
            'notify_on_failure' => $this->new_schedule_notify,
        ]);

        $this->show_add_schedule_form = false;
        $this->reset(['new_schedule_preset', 'new_schedule_cron', 'new_schedule_notify']);
        $this->toastSuccess(__('Scheduled deploy added.'));
    }

    public function toggleDeploymentSchedule(string $id): void
    {
        $this->authorize('update', $this->site);

        $schedule = $this->site->deploymentSchedules()->whereKey($id)->first();
        if ($schedule === null) {
            return;
        }

        $schedule->update([
            'is_active' => ! $schedule->is_active,
            // Clear the failure streak when an operator re-enables a paused one.
            'consecutive_failures' => $schedule->is_active ? $schedule->consecutive_failures : 0,
        ]);

        $this->toastSuccess($schedule->is_active ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function deleteDeploymentSchedule(string $id): void
    {
        $this->authorize('update', $this->site);

        $this->site->deploymentSchedules()->whereKey($id)->delete();
        $this->toastSuccess(__('Scheduled deploy removed.'));
    }

    public function runDeploymentScheduleNow(string $id): void
    {
        $this->authorize('update', $this->site);

        $schedule = $this->site->deploymentSchedules()->whereKey($id)->first();
        if ($schedule === null) {
            return;
        }

        Cache::put('site-deploy-active:'.$this->site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);

        RunSiteDeploymentJob::dispatch($this->site->fresh(), SiteDeployment::TRIGGER_SCHEDULE);
        $this->toastSuccess(__('Deploy queued from schedule. Watch the phase timeline below.'));
    }
}
