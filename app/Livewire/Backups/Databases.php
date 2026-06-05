<?php

namespace App\Livewire\Backups;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Databases extends Component
{
    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        return view('livewire.backups.databases');
    }
}
