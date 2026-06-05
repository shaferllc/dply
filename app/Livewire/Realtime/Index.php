<?php

declare(strict_types=1);

namespace App\Livewire\Realtime;

use App\Models\RealtimeApp;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        if (! Feature::for($org)->active('surface.realtime')) {
            return view('livewire.realtime.index', ['featureActive' => false]);
        }

        $apps = RealtimeApp::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.realtime.index', [
            'featureActive' => true,
            'apps'          => $apps,
            'priceCents'    => (int) config('realtime.plan.price_cents'),
        ]);
    }
}
