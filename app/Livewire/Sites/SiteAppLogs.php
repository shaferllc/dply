<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\AppLogRecord;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The dply Realtime "App logs" surface (Phase 5, Q12) — a per-site list of
 * application log records received via the dply Realtime drain. Deliberately a
 * simple paged/filtered reader: the full live-tail viewer (streaming, retention
 * controls, full-text search) is an explicit later product phase. Kept SEPARATE
 * from the platform Errors stream and from SiteLogViewer (server system logs).
 */
class SiteAppLogs extends Component
{
    public Site $site;

    public string $levelFilter = '';

    public string $search = '';

    public int $limit = 100;

    public function refresh(): void
    {
        // No-op: re-renders and re-queries on the Livewire round-trip.
    }

    public function loadMore(): void
    {
        $this->limit = min($this->limit + 100, 1000);
    }

    public function render(): View
    {
        $query = AppLogRecord::query()
            ->where('site_id', $this->site->id)
            ->latest('created_at');

        if ($this->levelFilter !== '') {
            $query->where('level', $this->levelFilter);
        }
        if (trim($this->search) !== '') {
            $query->where('message', 'like', '%'.trim($this->search).'%');
        }

        return view('livewire.sites.site-app-logs', [
            'records' => $query->limit($this->limit)->get(),
            'levels' => ['', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        ]);
    }
}
