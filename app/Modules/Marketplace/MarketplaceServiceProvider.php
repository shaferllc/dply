<?php

declare(strict_types=1);

namespace App\Modules\Marketplace;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Marketplace module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The script-marketplace feature: MarketplaceImportService, the org-script
 * services (under Scripts\), and the marketplace/scripts Livewire pages.
 * Registers the full-page + modal components under their original names. The
 * MarketplaceItem/Script models stay in app/Models.
 *
 * NOTE: the provisioning/setup "script" jobs (RunSetupScriptJob, etc.) are a
 * different concept (server provisioning) and deliberately stay in the shell.
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('marketplace.index', \App\Modules\Marketplace\Livewire\Index::class);
        Livewire::component('scripts.index', \App\Modules\Marketplace\Livewire\Scripts\Index::class);
        Livewire::component('scripts.create', \App\Modules\Marketplace\Livewire\Scripts\Create::class);
        Livewire::component('scripts.edit', \App\Modules\Marketplace\Livewire\Scripts\Edit::class);
        Livewire::component('scripts.marketplace', \App\Modules\Marketplace\Livewire\Scripts\Marketplace::class);
        Livewire::component('scripts.marketplace-modal', \App\Modules\Marketplace\Livewire\Scripts\MarketplaceModal::class);
    }
}
