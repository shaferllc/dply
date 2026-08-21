<?php

declare(strict_types=1);

namespace App\Modules\Serverless;

use App\Modules\Serverless\Livewire\BackgroundPanel;
use App\Modules\Serverless\Livewire\AssetsPanel;
use App\Modules\Serverless\Livewire\Create;
use App\Modules\Serverless\Livewire\DatabasePanel;
use App\Modules\Serverless\Livewire\DnsPanel;
use App\Modules\Serverless\Livewire\Glue;
use App\Modules\Serverless\Livewire\Index;
use App\Modules\Serverless\Livewire\Journey;
use App\Modules\Serverless\Livewire\LogsPanel;
use App\Modules\Serverless\Livewire\PlatformPanel;
use App\Modules\Serverless\Livewire\RollbackPanel;
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
        $this->aliasServerlessAssetsDisk();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CollectServerlessUsageCommand::class,
                Console\ServerlessTickCommand::class,
                Console\ServerlessQueueDoctorCommand::class,
                Console\ServerlessFilesystemDoctorCommand::class,
                Console\BackfillFunctionActionsCommand::class,
                Console\PruneFunctionInvocationsCommand::class,
                Console\ServerlessManifestCommand::class,
                Console\SweepServerlessAssetsCommand::class,
                Console\BackfillServerlessAssetsCommand::class,
                Console\EvaluateServerlessAssetGuardrailsCommand::class,
                Console\SetupServerlessAssetBucketCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('serverless.create', Create::class);
        Livewire::component('serverless.glue', Glue::class);
        Livewire::component('serverless.index', Index::class);
        Livewire::component('serverless.journey', Journey::class);
        Livewire::component('serverless.background-panel', BackgroundPanel::class);
        Livewire::component('serverless.assets-panel', AssetsPanel::class);
        Livewire::component('serverless.database-panel', DatabasePanel::class);
        Livewire::component('serverless.dns-panel', DnsPanel::class);
        Livewire::component('serverless.logs-panel', LogsPanel::class);
        Livewire::component('serverless.platform-panel', PlatformPanel::class);
        Livewire::component('serverless.rollback-panel', RollbackPanel::class);
    }

    /**
     * Leftover `Storage::disk('serverless_assets')` calls alias the durable
     * attached store (`site_assets`) so they do not throw after that invented
     * disk was removed from filesystems.php.
     */
    private function aliasServerlessAssetsDisk(): void
    {
        $existing = config('filesystems.disks.serverless_assets');
        if (is_array($existing) && filled($existing['driver'] ?? null)) {
            return;
        }

        $source = config('filesystems.disks.site_assets');
        if (! is_array($source) || ! filled($source['driver'] ?? null)) {
            return;
        }

        // Same root as site_assets, but do not register a second /site-assets
        // serve route — Laravel requires unique URLs for served disks.
        $source['serve'] = false;

        config(['filesystems.disks.serverless_assets' => $source]);
    }
}
