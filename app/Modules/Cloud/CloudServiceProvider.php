<?php

declare(strict_types=1);

namespace App\Modules\Cloud;

use Illuminate\Support\ServiceProvider;

/**
 * Cloud module wiring (docs/adr/modular-monolith-structure.md).
 * Provider SDK/backend services need no registration; this registers the full
 * Cloud CLI (PaaS lifecycle: deploy/redeploy/rollback/teardown, databases,
 * domains, previews, workers, scheduler, autoscale, health, provider VM-image
 * snapshots) relocated from app/Console/Commands.
 */
class CloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CloudAutoscaleCommand::class,
                Console\CloudCancelCommand::class,
                Console\CloudDatabaseAttachCommand::class,
                Console\CloudDatabaseCreateCommand::class,
                Console\CloudDatabaseDetachCommand::class,
                Console\CloudDatabaseListCommand::class,
                Console\CloudDatabaseTeardownCommand::class,
                Console\CloudDeployCommand::class,
                Console\CloudDeploymentsCommand::class,
                Console\CloudDoctorCommand::class,
                Console\CloudDomainAttachCommand::class,
                Console\CloudDomainDetachCommand::class,
                Console\CloudDomainListCommand::class,
                Console\CloudEnvCommand::class,
                Console\CloudHealthCheckCommand::class,
                Console\CloudListCommand::class,
                Console\CloudLogsCommand::class,
                Console\CloudMetricsCommand::class,
                Console\CloudPollStatusCommand::class,
                Console\CloudPreviewCreateCommand::class,
                Console\CloudPreviewListCommand::class,
                Console\CloudPreviewTeardownCommand::class,
                Console\CloudRedeployCommand::class,
                Console\CloudResizeCommand::class,
                Console\CloudRollbackCommand::class,
                Console\CloudScaleCommand::class,
                Console\CloudSchedulerDisableCommand::class,
                Console\CloudSchedulerEnableCommand::class,
                Console\CloudTeardownCommand::class,
                Console\CloudWorkerAddCommand::class,
                Console\CloudWorkerListCommand::class,
                Console\CloudWorkerRemoveCommand::class,
                Console\CloudWorkerScaleCommand::class,
                Console\SnapshotBakeDigitalOceanCommand::class,
                Console\SnapshotBakeHetznerCommand::class,
                Console\SnapshotDeleteDigitalOceanCommand::class,
                Console\SnapshotListDigitalOceanCommand::class,
            ]);
        }
    }
}
