<?php

declare(strict_types=1);

namespace App\Modules\Deploy;

use App\Modules\Deploy\Console\AbortSiteDeployCommand;
use App\Modules\Deploy\Console\DeploySiteCommand;
use App\Modules\Deploy\Console\EnableSiteQuickDeployCommand;
use App\Modules\Deploy\Console\FlushDeployDigestCommand;
use App\Modules\Deploy\Console\PredeployCommand;
use App\Modules\Deploy\Console\RedeploySiteSystemdCommand;
use App\Modules\Deploy\Console\RunDueDeploymentSchedulesCommand;
use App\Modules\Deploy\Console\RunDueScheduledDeploysCommand;
use App\Modules\Deploy\Console\ShowSiteDeployCommand;
use App\Modules\Deploy\Console\SiteDeployHistoryCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Deploy module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Phase 2: re-registers the site-deploy commands (3 scheduled in DplySchedule via
 * repointed refs). RunSiteDeploymentJob dispatches by class. The deploy engine
 * (Services, phase 1) needs no registration.
 *
 * Deliberately NOT in this module (other domains / shell): Cloud* deploy commands
 * (Cloud), *Fleet deploy commands (Fleet/shell), DeployIntelligenceScanCommand
 * (DeployIntelligence), workspace/guest-metrics deploy jobs; the ~40 deploy Livewire
 * tabs/concerns + Support/Sites deploy-pipeline helpers stay in the shell; the 16
 * deploy models stay in app/Models.
 */
class DeployServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AbortSiteDeployCommand::class,
                DeploySiteCommand::class,
                EnableSiteQuickDeployCommand::class,
                FlushDeployDigestCommand::class,
                PredeployCommand::class,
                RedeploySiteSystemdCommand::class,
                RunDueDeploymentSchedulesCommand::class,
                RunDueScheduledDeploysCommand::class,
                ShowSiteDeployCommand::class,
                SiteDeployHistoryCommand::class,
            ]);
        }
    }
}
