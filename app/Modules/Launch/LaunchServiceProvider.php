<?php

declare(strict_types=1);

namespace App\Modules\Launch;

use App\Modules\Launch\Livewire\Create;
use App\Modules\Launch\Livewire\FullStack;
use App\Modules\Launch\Livewire\Path;
use App\Modules\Launch\Livewire\StandbyBlueprint;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Launch module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Consolidates the former Launch (Services/Support) + Launches (Livewire) split
 * into one module. Registers the 4 full-page route components under their
 * original auto-derived names. FinalizeContainerCloudLaunchJob is dispatched by
 * class (no registration needed).
 */
class LaunchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('launches.create', Create::class);
        Livewire::component('launches.full-stack', FullStack::class);
        Livewire::component('launches.path', Path::class);
        Livewire::component('launches.standby-blueprint', StandbyBlueprint::class);
    }
}
