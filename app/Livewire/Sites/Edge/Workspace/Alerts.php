<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * RUM-style alerting (P58). Per-site thresholds for LCP p75, 5xx rate,
 * and 5xx count. CheckEdgeRumAlertsCommand runs hourly and publishes
 * `edge.rum.breach` notification events when any enabled threshold is
 * crossed over the last hour. Cooldown is 6h per (site, kind).
 */
class Alerts extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    public bool $lcp_enabled = false;

    #[Validate('nullable|integer|min:100|max:60000')]
    public int $lcp_threshold = 2500;

    public bool $err_rate_enabled = false;

    #[Validate('nullable|numeric|min:0.1|max:100')]
    public float $err_rate_threshold = 5.0;

    public bool $err_count_enabled = false;

    #[Validate('nullable|integer|min:1|max:1000000')]
    public int $err_count_threshold = 50;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $alerts = is_array($site->edgeMeta()['alerts'] ?? null) ? $site->edgeMeta()['alerts'] : [];
        $lcp = is_array($alerts['lcp_p75_ms'] ?? null) ? $alerts['lcp_p75_ms'] : [];
        $err = is_array($alerts['error_rate'] ?? null) ? $alerts['error_rate'] : [];
        $cnt = is_array($alerts['five_xx_count'] ?? null) ? $alerts['five_xx_count'] : [];

        $this->lcp_enabled = (bool) ($lcp['enabled'] ?? false);
        $this->lcp_threshold = (int) ($lcp['threshold'] ?? 2500);
        $this->err_rate_enabled = (bool) ($err['enabled'] ?? false);
        $this->err_rate_threshold = (float) ($err['threshold'] ?? 5.0);
        $this->err_count_enabled = (bool) ($cnt['enabled'] ?? false);
        $this->err_count_threshold = (int) ($cnt['threshold'] ?? 50);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $this->validate();

        $previous = is_array($this->site->edgeMeta()['alerts'] ?? null) ? $this->site->edgeMeta()['alerts'] : [];

        $this->site->mergeEdgeMeta([
            'alerts' => [
                'lcp_p75_ms' => ['enabled' => $this->lcp_enabled, 'threshold' => $this->lcp_threshold],
                'error_rate' => ['enabled' => $this->err_rate_enabled, 'threshold' => $this->err_rate_threshold],
                'five_xx_count' => ['enabled' => $this->err_count_enabled, 'threshold' => $this->err_count_threshold],
            ],
        ]);
        $this->site->save();

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.alerts.updated',
            $this->site,
            ['alerts' => $previous],
            ['alerts' => $this->site->edgeMeta()['alerts']],
        );

        $this->toastSuccess(__('Alert thresholds saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.alerts', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-alerts'),
            ['server' => $this->server, 'site' => $this->site],
        ));
    }
}
