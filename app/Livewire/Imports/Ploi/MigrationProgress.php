<?php

declare(strict_types=1);

namespace App\Livewire\Imports\Ploi;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
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
