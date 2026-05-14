<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Services\Imports\StepHandler;
use RuntimeException;

/**
 * Base class for handlers whose implementations require SSH access to a
 * provisioned dply target server and/or the source Ploi server: code clone,
 * env copy, DB dump/restore, recreate crons/daemons/scheduler, SSL setup,
 * and the cutover sub-plan.
 *
 * Each requires real infrastructure to validate end-to-end. The framework
 * is in place — these slots resolve to a handler that fails the step with a
 * clear message so the migration pauses at the first SSH-dependent step,
 * reporting precisely what still needs implementing. This is honest about
 * project state: connect + inventory + plan + SSH-key lifecycle work today,
 * data transfer needs phase 3b.
 */
abstract class PendingPhase3bHandler implements StepHandler
{
    public function execute(ImportMigrationStep $step): void
    {
        throw new RuntimeException(sprintf(
            'Step "%s" needs SSH to the target dply server; landing in phase 3b.',
            static::key()
        ));
    }
}
