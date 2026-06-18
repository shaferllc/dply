<?php

declare(strict_types=1);

namespace App\Modules\Insights;

use App\Modules\Insights\Console\DispatchServerInsightsCommand;
use App\Modules\Insights\Console\DispatchSiteInsightsCommand;
use App\Modules\Insights\Console\ProcessInsightDigestQueueCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Insights module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The insights engine: Services (incl. Contracts/FixActions/Runners), the run/
 * apply/revert Jobs, and the dispatch/digest commands re-registered here.
 *
 * No Livewire registration: the Workspace*Insights components are workspace tabs
 * of the Servers/Sites hub domains and stay there (they consume this engine via
 * App\Modules\Insights\Services\*). The insight models stay in app/Models per the
 * model rule; VatInsightService is a Billing concern and stayed in that domain.
 */
class InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchServerInsightsCommand::class,
                DispatchSiteInsightsCommand::class,
                ProcessInsightDigestQueueCommand::class,
            ]);
        }
    }
}
