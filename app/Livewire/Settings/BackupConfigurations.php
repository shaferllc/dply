<?php

namespace App\Livewire\Settings;

use App\Livewire\Backups\Storage as BackupsStorage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;

/**
 * Backup destinations, on the settings surface.
 *
 * Destinations live in the product they configure (/backups/storage, see
 * docs/adr/backups-as-a-product.md decision 13) — that page keeps the usage
 * console: coverage dial, write trends, what points at each bucket. This is the
 * plain management surface the settings nav asks for: the same rows, the same
 * modal, no analytics.
 *
 * It subclasses the Backups page rather than re-implementing it, so create /
 * edit / delete, validation, auditing, and the shared destination modal are
 * literally the same code — only the layout and the view differ.
 */
#[Layout('layouts.settings')]
class BackupConfigurations extends BackupsStorage
{
    public function render(): View
    {
        // Reuse the parent's query work, then swap the view. The parent returns
        // a View, so its bound data comes back through getData().
        $data = parent::render()->getData();

        return view('livewire.settings.backup-configurations', $data);
    }
}
