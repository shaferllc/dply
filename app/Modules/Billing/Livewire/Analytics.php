<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Organization;
use App\Modules\Billing\Services\BillingAnalytics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Analytics extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function render(BillingAnalytics $billingAnalytics): View
    {
        $analytics = $billingAnalytics->forOrganization($this->organization);

        // Seven of the fourteen payloads forOrganization() returns are no longer
        // rendered (2026-08-22): edge_usage_daily / edge_sites / managed_products
        // were dead once the Edge, Cloud and Serverless surfaces went; sync_events
        // is operator telemetry that belongs in admin, not on a page the customer
        // reads; billable_servers / excluded_servers duplicated the observatory's
        // per-server table; subscription was already unused. The service still
        // computes them — narrowing that is a separate change.
        return view('livewire.billing.analytics', [
            'costObservatory' => $analytics['cost_observatory'] ?? [],
            'summary' => $analytics['summary'] ?? [],
            'forecast' => $analytics['forecast'] ?? [],
            'spendTrend' => $analytics['spend_trend'] ?? [],
            'categoryBreakdown' => $analytics['category_breakdown'] ?? [],
            'lineItems' => $analytics['line_items'] ?? [],
            'invoiceHistory' => $analytics['invoice_history'] ?? [],
        ]);
    }
}
