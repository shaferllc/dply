<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Drives a migration forward one step at a time. The orchestrator does NOT
 * iterate the whole plan — each step is its own queue job (RunMigrationStepJob)
 * so failures, retries, and pauses are visible at the Laravel queue level and
 * concurrent runs can race-free pick from the same plan.
 *
 * The orchestrator pauses execution at the cutover gate: it only auto-advances
 * through STAGING_STEPS for a site. Cutover steps require an explicit user
 * trigger (per Q8 / Q9b — the user owns the cutover moment).
 *
 * Failures: 5 attempts, then leave the step in `failed` and pause the parent
 * migration. The user resolves via the UI (retry / skip / abort).
 */
class StepOrchestrator
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(protected StepRegistry $registry) {}

    public function executeStep(ImportMigrationStep $step): void
    {
        if ($step->isTerminal()) {
            return;
        }

        $this->markRunning($step);
        try {
            $handler = $this->registry->resolve($step->step_key);
            $handler->execute($step->refresh());
            $this->markSucceeded($step);
            $this->maybeAdvanceMigration($step);
        } catch (WaitForTargetServerException $e) {
            // Don't mark failed — this is a wait state. ServerObserver re-dispatches
            // the step when the target Server transitions to READY.
            Log::info('import migration step paused waiting for target server', [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
            ]);
            $this->markPendingAfterWait($step, $e);
        } catch (WaitForTargetSiteException $e) {
            // Same shape — paused on Site provisioning.
            Log::info('import migration step paused waiting for target site', [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
            ]);
            $this->markPendingAfterWait($step, $e);
        } catch (Throwable $e) {
            Log::warning('import migration step failed', [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($step, $e);
        }
    }

    /**
     * Returns the next eligible pending step for the migration, or null if there is
     * no further auto-runnable step (either everything terminal, or we hit the
     * cutover gate and the user has not yet initiated cutover).
     */
    public function nextStep(ImportServerMigration $migration): ?ImportMigrationStep
    {
        return ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->where('status', ImportMigrationStep::STATUS_PENDING)
            ->whereNotIn('step_key', MigrationPlanner::CUTOVER_STEPS)
            ->orderBy('sequence')
            ->first();
    }

    protected function markRunning(ImportMigrationStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $step->refresh();
            $step->status = ImportMigrationStep::STATUS_RUNNING;
            $step->attempts++;
            $step->started_at ??= Carbon::now();
            $step->save();
        });
    }

    protected function markSucceeded(ImportMigrationStep $step): void
    {
        $step->status = ImportMigrationStep::STATUS_SUCCEEDED;
        $step->finished_at = Carbon::now();
        $step->error_message = null;
        $step->save();
    }

    protected function markFailed(ImportMigrationStep $step, Throwable $e): void
    {
        $step->status = ImportMigrationStep::STATUS_FAILED;
        $step->finished_at = Carbon::now();
        $step->error_message = mb_substr($e->getMessage(), 0, 5000);
        $step->save();
    }

    /**
     * Return a step to PENDING after a wait — the orchestrator's follow-up
     * dispatch will not enqueue it because nextStep() returns the first
     * PENDING step in sequence; instead the ServerObserver / SiteObserver
     * trigger a fresh dispatch when the underlying resource is ready.
     */
    protected function markPendingAfterWait(ImportMigrationStep $step, Throwable $e): void
    {
        $step->status = ImportMigrationStep::STATUS_PENDING;
        $step->error_message = mb_substr($e->getMessage(), 0, 5000);
        $step->started_at = null;
        $step->save();
    }

    /**
     * After a successful step, push the parent and (when applicable) the child
     * site status forward. The full state-transition logic — staging_completed_at,
     * ready_for_cutover, etc. — lives here so handlers stay thin.
     */
    protected function maybeAdvanceMigration(ImportMigrationStep $step): void
    {
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            return;
        }

        if ($migration->status === ImportServerMigration::STATUS_PENDING) {
            $migration->status = ImportServerMigration::STATUS_STAGING;
            $migration->started_at ??= Carbon::now();
            $migration->save();
        }

        if ($step->import_site_migration_id !== null && in_array($step->step_key, MigrationPlanner::STAGING_STEPS, true)) {
            $this->maybeMarkSiteReady($step->import_site_migration_id);
        }
    }

    protected function maybeMarkSiteReady(string $siteMigrationId): void
    {
        $site = ImportSiteMigration::find($siteMigrationId);
        if ($site === null) {
            return;
        }

        $remainingStaging = ImportMigrationStep::query()
            ->where('import_site_migration_id', $site->id)
            ->whereIn('step_key', MigrationPlanner::STAGING_STEPS)
            ->where('status', '!=', ImportMigrationStep::STATUS_SUCCEEDED)
            ->exists();

        if (! $remainingStaging && $site->status === ImportSiteMigration::STATUS_PENDING) {
            $site->status = ImportSiteMigration::STATUS_READY_FOR_CUTOVER;
            $site->staging_completed_at = Carbon::now();
            $site->save();
        } elseif (! $remainingStaging && $site->status === ImportSiteMigration::STATUS_STAGING) {
            $site->status = ImportSiteMigration::STATUS_READY_FOR_CUTOVER;
            $site->staging_completed_at = Carbon::now();
            $site->save();
        } elseif ($site->status === ImportSiteMigration::STATUS_PENDING) {
            $site->status = ImportSiteMigration::STATUS_STAGING;
            $site->save();
        }
    }
}
