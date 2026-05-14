<?php

declare(strict_types=1);

namespace App\Livewire\Imports\Ploi;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Services\Imports\MigrationPlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Migration run inspector. Surfaces the declared step plan (per-server +
 * per-site) with status pills so the user always knows where their migration
 * is, what failed, and what to do next.
 *
 * Polling: 3s while any step is `running`, 10s while there are pending
 * stage-tier steps with no failures (so the page picks up the next step
 * landing without burning the database), off entirely when everything is
 * terminal.
 */
#[Layout('layouts.app')]
class MigrationProgress extends Component
{
    use DispatchesToastNotifications;

    public ImportServerMigration $migration;

    public function mount(ImportServerMigration $migration): void
    {
        $this->authorizeView($migration);
        $this->migration = $migration->load(['siteMigrations.steps', 'steps' => fn ($q) => $q->orderBy('sequence')]);
    }

    public function retryFailedStep(string $stepId): void
    {
        $step = ImportMigrationStep::query()
            ->where('id', $stepId)
            ->where('import_server_migration_id', $this->migration->id)
            ->where('status', ImportMigrationStep::STATUS_FAILED)
            ->first();

        if ($step === null) {
            $this->toastError(__('Step is not in a retryable state.'));

            return;
        }

        $step->status = ImportMigrationStep::STATUS_PENDING;
        $step->error_message = null;
        $step->save();

        RunMigrationStepJob::dispatch($step->id);
        $this->toastSuccess(__('Retry queued.'));
    }

    /**
     * Begin cutover for a single child site. Only valid when the site is in
     * READY_FOR_CUTOVER and no cutover step is already running. Dispatches the
     * first cutover step (cutover_maintenance_on); from there the orchestrator
     * walks the cutover sub-plan.
     */
    public function beginCutover(string $siteMigrationId): void
    {
        $child = ImportSiteMigration::query()
            ->where('id', $siteMigrationId)
            ->where('import_server_migration_id', $this->migration->id)
            ->first();

        if ($child === null) {
            $this->toastError(__('Site migration not found.'));

            return;
        }
        if ($child->status !== ImportSiteMigration::STATUS_READY_FOR_CUTOVER) {
            $this->toastError(__('Site is not ready for cutover yet (status: :status).', ['status' => $child->status]));

            return;
        }

        $firstCutoverStep = ImportMigrationStep::query()
            ->where('import_site_migration_id', $child->id)
            ->whereIn('step_key', MigrationPlanner::CUTOVER_STEPS)
            ->where('status', ImportMigrationStep::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();

        if ($firstCutoverStep === null) {
            $this->toastError(__('No pending cutover steps found.'));

            return;
        }

        RunMigrationStepJob::dispatch($firstCutoverStep->id);
        $this->toastSuccess(__('Cutover started for :domain.', ['domain' => $child->domain]));
    }

    public function render(): View
    {
        $this->migration->refresh();
        $this->migration->load(['siteMigrations.steps', 'steps' => fn ($q) => $q->orderBy('sequence'), 'targetServer']);

        return view('livewire.imports.ploi.migration-progress', [
            'migration' => $this->migration,
            'serverSteps' => $this->migration->steps->whereNull('import_site_migration_id')->values(),
            'shouldPoll' => $this->shouldPoll(),
        ]);
    }

    protected function shouldPoll(): bool
    {
        return $this->migration->steps()
            ->whereIn('status', [ImportMigrationStep::STATUS_PENDING, ImportMigrationStep::STATUS_RUNNING])
            ->exists();
    }

    protected function authorizeView(ImportServerMigration $migration): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        $org = $user->currentOrganization();
        if ($org === null || $migration->organization_id !== $org->getKey()) {
            abort(403);
        }
    }
}
