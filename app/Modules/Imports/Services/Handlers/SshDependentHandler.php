<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Imports\Services\StepHandler;
use App\Modules\Imports\Services\WaitForTargetServerException;
use App\Modules\Imports\Services\WaitForTargetSiteException;
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

        // For per-site steps, also gate on the target Site being provisioned. Steps
        // like clone_repo, restore_database, setup_ssl all need /home/{slug}/ to
        // exist, which only happens after ProvisionSiteJob completes.
        if ($this->requiresProvisionedTargetSite() && $step->import_site_migration_id !== null) {
            $child = ImportSiteMigration::find($step->import_site_migration_id);
            if ($child === null || $child->target_site_id === null) {
                throw new RuntimeException('SSH step requires target_site_id on the child migration.');
            }
            $site = Site::find($child->target_site_id);
            if ($site === null) {
                throw new RuntimeException('Target dply site row missing for step.');
            }
            if (! $site->isReadyForTraffic()) {
                throw new WaitForTargetSiteException(sprintf(
                    'Target dply site %s is %s; waiting for provisioning to finish.',
                    $site->name,
                    $site->status,
                ));
            }
        }

        $this->executeOnReadyServer($step, $migration, $target);
    }

    /**
     * Default true: SSH-dependent handlers usually need the target Site provisioned.
     * Override in subclasses that only need the Server (e.g. server-level steps).
     */
    protected function requiresProvisionedTargetSite(): bool
    {
        return true;
    }

    abstract protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void;
}
