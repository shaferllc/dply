<?php

namespace App\Livewire;

use App\Services\Insights\OrganizationInsightsMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $user = auth()->user();
        $servers = $user->servers()->latest()->take(5)->get();
        $org = $user->currentOrganization();
        $fleetInsights = $insightsMetrics->fleetSummary($org);

        return view('livewire.dashboard', [
            'servers' => $servers,
            'fleetInsights' => $fleetInsights,
        ]);
    }
}
