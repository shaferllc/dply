<?php

declare(strict_types=1);

namespace App\Modules\Cloud;

use Illuminate\Support\ServiceProvider;

/**
 * Cloud module command wiring (docs/adr/modular-monolith-structure.md).
 * The provider SDK/backend services need no registration; this registers the
 * Cloud CLI (doctor/resize/scale/cancel/env/logs/metrics + provider VM-image
 * snapshot bake/delete/list commands) relocated from app/Console/Commands.
 */
class CloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CloudDoctorCommand::class,
                Console\CloudResizeCommand::class,
                Console\CloudScaleCommand::class,
                Console\CloudCancelCommand::class,
                Console\CloudEnvCommand::class,
                Console\CloudLogsCommand::class,
                Console\CloudMetricsCommand::class,
                Console\SnapshotBakeDigitalOceanCommand::class,
                Console\SnapshotDeleteDigitalOceanCommand::class,
                Console\SnapshotListDigitalOceanCommand::class,
                Console\SnapshotBakeHetznerCommand::class,
            ]);
        }
    }
}
