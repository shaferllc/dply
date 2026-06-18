<?php

declare(strict_types=1);

namespace App\Modules\Roadmap;

use App\Modules\Roadmap\Console\CompileRoadmapsCommand;
use App\Modules\Roadmap\Console\RoadmapAiUpdateCommand;
use App\Modules\Roadmap\Livewire\Admin\Index as AdminRoadmapIndex;
use App\Modules\Roadmap\Livewire\Index as RoadmapIndex;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Roadmap module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Moving the commands out of app/Console/Commands removes them from Laravel's
 * command auto-discovery, so they are re-registered here. PredeployCommand
 * invokes them by their artisan name (dply:docs:compile-roadmaps, dply:roadmap:ai-update),
 * which only resolves if they remain registered.
 *
 * The two Index components are full-page route components (Route::livewire in
 * routes/web.php). Moving them out of App\Livewire breaks Livewire's class->name
 * derivation at *render* time (route:list does not catch this — only an HTTP
 * render does), so they are registered here under their original auto-derived
 * names to preserve component identity.
 */
class RoadmapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CompileRoadmapsCommand::class,
                RoadmapAiUpdateCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('roadmap.index', RoadmapIndex::class);
        Livewire::component('admin.roadmap.index', AdminRoadmapIndex::class);
    }
}
