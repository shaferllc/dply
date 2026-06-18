<?php

declare(strict_types=1);

namespace App\Modules\Serverless;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Serverless module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The FaaS feature core: Services (incl. Aws/Backends), Actions, Support, the
 * function-proxy controller + custom-domain middleware (middleware stays wired in
 * bootstrap/app.php via a repointed reference), the contract/exception, the
 * provisioning/rollback jobs, and the tick/collect-usage commands.
 *
 * Re-registers the commands and all 11 Livewire components (4 full-page route
 * components + 7 embedded panels) under their original serverless.* names.
 *
 * The serverless DEPLOY adapters (App\Modules\Deploy\Services\Serverless*) and BILLING
 * usage services (App\Modules\Billing\Services\Serverless*) stay in those hub domains;
 * they reference this module's contract/services via repointed imports. The
 * function models stay in app/Models per the model rule.
 */
class ServerlessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CollectServerlessUsageCommand::class,
                Console\ServerlessTickCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('serverless.create', \App\Modules\Serverless\Livewire\Create::class);
        Livewire::component('serverless.glue', \App\Modules\Serverless\Livewire\Glue::class);
        Livewire::component('serverless.index', \App\Modules\Serverless\Livewire\Index::class);
        Livewire::component('serverless.journey', \App\Modules\Serverless\Livewire\Journey::class);
        Livewire::component('serverless.background-panel', \App\Modules\Serverless\Livewire\BackgroundPanel::class);
        Livewire::component('serverless.cache-panel', \App\Modules\Serverless\Livewire\CachePanel::class);
        Livewire::component('serverless.database-panel', \App\Modules\Serverless\Livewire\DatabasePanel::class);
        Livewire::component('serverless.dns-panel', \App\Modules\Serverless\Livewire\DnsPanel::class);
        Livewire::component('serverless.logs-panel', \App\Modules\Serverless\Livewire\LogsPanel::class);
        Livewire::component('serverless.platform-panel', \App\Modules\Serverless\Livewire\PlatformPanel::class);
        Livewire::component('serverless.rollback-panel', \App\Modules\Serverless\Livewire\RollbackPanel::class);
    }
}
