<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Hub extends Component
{
    public function render(): View
    {
        return view('livewire.settings.hub');
    }
}
