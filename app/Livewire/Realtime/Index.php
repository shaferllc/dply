<?php

declare(strict_types=1);

namespace App\Livewire\Realtime;

use App\Models\RealtimeApp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-scoped index of managed realtime apps. Routed at `realtime.index`;
 * feature-gated by `surface.realtime`.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $apps = RealtimeApp::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.realtime.index', [
            'apps' => $apps,
            'priceCents' => (int) config('realtime.plan.price_cents'),
        ]);
    }
}
