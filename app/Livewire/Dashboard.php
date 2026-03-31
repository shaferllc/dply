<?php

namespace App\Livewire;

use App\Models\ProviderCredential;
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
        $serverQuery = $user->servers()->latest();
        $servers = (clone $serverQuery)->take(5)->get();
        $serverCount = (clone $serverQuery)->count();
        $org = $user->currentOrganization();
        $fleetInsights = $insightsMetrics->fleetSummary($org);
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;

        return view('livewire.dashboard', [
            'organization' => $org,
            'servers' => $servers,
            'serverCount' => $serverCount,
            'fleetInsights' => $fleetInsights,
            'hasProviderCredentials' => $hasProviderCredentials,
        ]);
    }
}
