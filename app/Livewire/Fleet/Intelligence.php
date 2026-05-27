<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\DeployIntelligenceAlert;
use App\Services\DeployIntelligence\Scanner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Open deploy-intelligence alerts for the current org. Drives the
 * "Intelligence" Fleet tab — operators dismiss alerts they don't care
 * about, the scanner refreshes still-observed conditions, alerts
 * whose conditions clear auto-resolve.
 *
 * Read-mostly: only mutation is dismissing alerts; the scanner does
 * the heavy lifting on cron. Operators can trigger an immediate scan
 * via the "Scan now" button (queued synchronously here since the work
 * is cheap and operators expect immediate feedback).
 */
class Intelligence extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'rule', except: '')]
    public string $ruleFilter = '';

    #[Url(as: 'show', except: 'open')]
    public string $showFilter = 'open';

    public function dismiss(string $alertId): void
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $alert = DeployIntelligenceAlert::query()
            ->where('organization_id', $org->id)
            ->whereKey($alertId)
            ->first();
        if ($alert === null) {
            return;
        }

        $alert->update([
            'dismissed_at' => now(),
            'dismissed_by_user_id' => auth()->id(),
        ]);
    }

    public function rescan(Scanner $scanner): void
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $scanner->scan($org);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $query = DeployIntelligenceAlert::query()
            ->where('organization_id', $org->id);

        if ($this->ruleFilter !== '') {
            $query->where('rule_key', $this->ruleFilter);
        }

        match ($this->showFilter) {
            'dismissed' => $query->whereNotNull('dismissed_at'),
            'resolved' => $query->whereNotNull('resolved_at')->whereNull('dismissed_at'),
            'all' => null,
            default => $query->whereNull('resolved_at')->whereNull('dismissed_at'),
        };

        $alerts = $query
            ->orderByDesc('severity')
            ->orderByDesc('last_observed_at')
            ->limit(200)
            ->get();

        $totals = [
            'open' => DeployIntelligenceAlert::query()
                ->where('organization_id', $org->id)
                ->whereNull('resolved_at')
                ->whereNull('dismissed_at')
                ->count(),
            'resolved' => DeployIntelligenceAlert::query()
                ->where('organization_id', $org->id)
                ->whereNotNull('resolved_at')
                ->whereNull('dismissed_at')
                ->count(),
            'dismissed' => DeployIntelligenceAlert::query()
                ->where('organization_id', $org->id)
                ->whereNotNull('dismissed_at')
                ->count(),
        ];

        return view('livewire.fleet.intelligence', [
            'alerts' => $alerts,
            'totals' => $totals,
        ])->layout('layouts.app');
    }
}
