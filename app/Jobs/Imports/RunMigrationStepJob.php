<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Services\Imports\StepOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one migration step, then queues the next pending step (if any) for the
 * same parent migration. Cutover steps are gated — the orchestrator's
 * nextStep() returns null past the gate, so this loop naturally stops at
 * ready_for_cutover and waits for explicit user action.
 *
 * WithoutOverlapping locks on the parent migration id so two jobs for the
 * same migration don't race on advancing state.
 */
class RunMigrationStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // handler retries are managed by the orchestrator, not the queue layer

    public function __construct(public string $stepId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Lock per-migration so step jobs for the same migration serialise.
        $step = ImportMigrationStep::find($this->stepId);
        $lockKey = ($step !== null ? $step->import_server_migration_id : null) ?? $this->stepId;

        return [
            (new WithoutOverlapping('import-migration:'.$lockKey))
                ->releaseAfter(20)
                ->expireAfter(900),
        ];
    }

    public function handle(StepOrchestrator $orchestrator): void
    {
        $step = ImportMigrationStep::find($this->stepId);
        if ($step === null) {
            return;
        }
        $orchestrator->executeStep($step);

        if ($step->refresh()->status !== ImportMigrationStep::STATUS_SUCCEEDED) {
            return; // pause on failure; user resolves via UI
        }

        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            return;
        }

        $next = $orchestrator->nextStep($migration);
        if ($next !== null) {
            self::dispatch($next->id);
        }
    }
}
