<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Server;
use App\Services\Imports\StepHandler;
use App\Services\Imports\WaitForTargetServerException;
use RuntimeException;

/**
 * Base for handlers that need SSH (or other dply-side operations) on the
 * provisioned target Server. Guards on Server::STATUS_READY before invoking
 * the concrete implementation; if the server isn't ready yet, throws
 * WaitForTargetServerException so the orchestrator leaves the step pending
 * for the ServerObserver to wake up.
 *
 * Subclasses implement executeOnReadyServer() to do the actual work, with
 * the target Server already resolved.
 */
abstract class SshDependentHandler implements StepHandler
{
    public function execute(ImportMigrationStep $step): void
    {
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing for SSH step '.$step->id);
        }

        $target = $migration->target_server_id
            ? Server::find($migration->target_server_id)
            : null;

        if ($target === null) {
            throw new RuntimeException('Target dply server not assigned to migration '.$migration->id);
        }

        if ($target->status !== Server::STATUS_READY) {
            throw new WaitForTargetServerException(sprintf(
                'Target dply server %s is %s; waiting for ready.',
                $target->name,
                $target->status,
            ));
        }

        $this->executeOnReadyServer($step, $migration, $target);
    }

    abstract protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void;
}
