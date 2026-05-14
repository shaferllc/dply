<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\ImportMigrationStep;

/**
 * Interface every migration-step implementation satisfies. The orchestrator
 * resolves a handler from the registry by step_key, calls execute(), and
 * translates exceptions into a failed-step row with the message stashed in
 * error_message. Successful return marks the step succeeded.
 *
 * Handlers should be idempotent — the orchestrator may re-run a step that
 * was marked running but never finished (worker crash, etc.). Persist any
 * external side-effect markers (key fingerprint, repository path, dump
 * filename) on result_data so re-runs can detect prior partial progress.
 */
interface StepHandler
{
    /** The step_key constant this handler is registered for. */
    public static function key(): string;

    public function execute(ImportMigrationStep $step): void;
}
