<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        protected StepRegistry $registry,
        protected ?NotificationPublisher $publisher = null,
    ) {}

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

        // Q13: when a CUTOVER step fails, transition the parent site to
        // cutover_failed so the progress UI can surface DNS rollback / manual
        // resolution affordances. Staging-tier failures stay at the step level
        // and use the existing retry/skip flow.
        if ($step->import_site_migration_id !== null
            && in_array($step->step_key, MigrationPlanner::CUTOVER_STEPS, true)
        ) {
            $child = ImportSiteMigration::find($step->import_site_migration_id);
            if ($child !== null
                && in_array($child->status, [
                    ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
                    ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
                ], true)
            ) {
                $child->status = ImportSiteMigration::STATUS_CUTOVER_FAILED;
                $child->failure_summary = mb_substr($e->getMessage(), 0, 1000);
                $child->save();
            }
        }

        $this->publishStepFailed($step);
    }

    /**
     * Action-required notification — surfaced in-app + via email per Q17.
     */
    protected function publishStepFailed(ImportMigrationStep $step): void
    {
        if ($this->publisher === null) {
            return;
        }
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            return;
        }
        $actor = User::find($migration->user_id);

        try {
            $this->publisher->publish(
                eventKey: 'import.migration.step_failed',
                subject: $migration,
                title: 'Migration step failed: '.$step->step_key,
                body: $step->error_message ?? 'Step failed without an error message.',
                url: route('imports.ploi.migration.progress', $migration),
                metadata: [
                    'step_id' => $step->id,
                    'step_key' => $step->step_key,
                    'migration_id' => $migration->id,
                ],
                actor: $actor,
            );
        } catch (Throwable $e) {
            Log::warning('failed to publish import.migration.step_failed', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
        }
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

        $becameReady = false;
        if (! $remainingStaging && in_array($site->status, [
            ImportSiteMigration::STATUS_PENDING,
            ImportSiteMigration::STATUS_STAGING,
        ], true)) {
            $site->status = ImportSiteMigration::STATUS_READY_FOR_CUTOVER;
            $site->staging_completed_at = Carbon::now();
            $site->save();
            $becameReady = true;
        } elseif ($site->status === ImportSiteMigration::STATUS_PENDING) {
            $site->status = ImportSiteMigration::STATUS_STAGING;
            $site->save();
        }

        if ($becameReady) {
            $this->publishCutoverReady($site);
        }
    }

    /**
     * Fire the action-required "cutover ready" notification per Q17. Only sent
     * once per site (status transition guard above ensures idempotency).
     */
    protected function publishCutoverReady(ImportSiteMigration $site): void
    {
        if ($this->publisher === null) {
            return;
        }
        $migration = ImportServerMigration::find($site->import_server_migration_id);
        if ($migration === null) {
            return;
        }
        $actor = User::find($migration->user_id);

        try {
            $this->publisher->publish(
                eventKey: 'import.migration.cutover_ready',
                subject: $migration,
                title: 'Cutover ready: '.$site->domain,
                body: 'Staging complete. Begin cutover from the migration progress page when you are ready.',
                url: route('imports.ploi.migration.progress', $migration),
                metadata: [
                    'site_migration_id' => $site->id,
                    'domain' => $site->domain,
                    'migration_id' => $migration->id,
                ],
                actor: $actor,
            );
        } catch (Throwable $e) {
            Log::warning('failed to publish import.migration.cutover_ready', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
