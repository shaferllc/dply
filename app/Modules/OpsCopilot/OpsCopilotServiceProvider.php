<?php

declare(strict_types=1);

namespace App\Modules\OpsCopilot;

use App\Modules\OpsCopilot\Livewire\OpsCopilot;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * OpsCopilot module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Registers the full-page Fleet copilot component under its original
 * auto-derived name (fleet.ops-copilot) so the Route::livewire('/fleet/copilot')
 * binding resolves it by class at render time. The analysis job is dispatched by
 * class (no registration needed); the services are referenced via
 * App\Modules\OpsCopilot\Services\* across the app.
 */
class OpsCopilotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('fleet.ops-copilot', OpsCopilot::class);
    }
}
