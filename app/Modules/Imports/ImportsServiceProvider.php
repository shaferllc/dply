<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Imports\Console\ExpirePausedImportMigrationsCommand;
use App\Modules\Imports\Console\ListImportMigrationsCommand;
use App\Modules\Imports\Console\ShowImportMigrationCommand;
use App\Modules\Imports\Livewire\Forge\Inventory as ForgeInventory;
use App\Modules\Imports\Livewire\Parity;
use App\Modules\Imports\Livewire\Ploi\Inventory as PloiInventory;
use App\Modules\Imports\Livewire\Ploi\MigrationProgress;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Imports module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The Forge/Ploi server+site migration feature. Re-registers the migration
 * commands (moved out of app/Console/Commands auto-discovery) and the full-page
 * route components (moved out of App\Livewire) under their original names.
 *
 * The ImportServerMigrationPolicy (Gate::policy) and ImportSiteWakeupObserver
 * (Site::observe) stay registered in AppServiceProvider — those references were
 * repointed at the move. The migration models remain in app/Models per the
 * model rule. Import helpers belonging to other domains (Edge/Certificates/
 * Marketplace/Sites) deliberately stay in those domains.
 */
class ImportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpirePausedImportMigrationsCommand::class,
                ListImportMigrationsCommand::class,
                ShowImportMigrationCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('imports.forge.inventory', ForgeInventory::class);
        Livewire::component('imports.parity', Parity::class);
        Livewire::component('imports.ploi.inventory', PloiInventory::class);
        Livewire::component('imports.ploi.migration-progress', MigrationProgress::class);
    }
}
