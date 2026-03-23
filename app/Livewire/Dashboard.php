<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        $servers = auth()->user()->servers()->latest()->take(5)->get();

        return view('livewire.dashboard', ['servers' => $servers]);
    }
}
