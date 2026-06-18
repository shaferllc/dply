<?php

declare(strict_types=1);

namespace App\Modules\Docs;

use App\Modules\Docs\Console\DocsFlushCommand;
use App\Modules\Docs\Console\DocsIndexCommand;
use App\Modules\Docs\Livewire\Sidebar;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Docs module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Re-registers the docs:flush / docs:index commands (moved out of
 * app/Console/Commands auto-discovery) and the docs.sidebar Livewire alias
 * (embedded as <livewire:docs.sidebar>, asserted by LivewireAliasGuardTest).
 *
 * DocsController is a plain controller (FQCN in routes/web.php); the Docs
 * services are referenced via App\Modules\Docs\Services\* across the app and in
 * Blade (app(...ContextualDocResolver::class)), all repointed at the move.
 */
class DocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DocsFlushCommand::class,
                DocsIndexCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('docs.sidebar', Sidebar::class);
    }
}
