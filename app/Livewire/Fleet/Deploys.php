<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Combined fleet-wide deploy activity dashboard. Three tabs:
 *
 *   - running:       in-flight deploys (newest first); long-running flagged
 *   - failed-latest: sites whose most recent settled deploy was failed
 *   - stale:         sites whose latest successful deploy is older than N days
 *
 * Mirrors the equivalent dply:fleet:running-deploys / failed-deploys /
 * stale-deploys CLIs but in a tabbed UI. Org-scoped to the user's
 * current organization.
 */
class Deploys extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    #[Url(as: 'tab', except: 'running')]
    public string $tab = 'running';

    #[Url(as: 'days', except: 30)]
    public int $staleDays = 30;

    public const ALLOWED_TABS = ['running', 'failed-latest', 'stale'];

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::ALLOWED_TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');

        $rows = match ($this->tab) {
            'failed-latest' => $this->collectFailedLatest($sites),
            'stale' => $this->collectStale($sites),
            default => $this->collectRunning($sites),
        };

        // Counts for tab pills.
        $counts = [
            'running' => SiteDeployment::query()
                ->whereIn('site_id', $sites->keys())
                ->where('status', SiteDeployment::STATUS_RUNNING)
                ->count(),
            'failed-latest' => $this->tab === 'failed-latest'
                ? count($rows)
                : count($this->collectFailedLatest($sites)),
            'stale' => $this->tab === 'stale'
                ? count($rows)
                : count($this->collectStale($sites)),
        ];

        return view('livewire.fleet.deploys', [
            'rows' => $rows,
            'counts' => $counts,
        ])->layout('layouts.app');
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function collectRunning($sites): array
    {
        $deployments = SiteDeployment::query()
            ->whereIn('site_id', $sites->keys())
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->orderBy('started_at')
            ->get(['id', 'site_id', 'trigger', 'started_at']);

        $rows = [];
        foreach ($deployments as $d) {
            $site = $sites->get($d->site_id);
            if ($site === null) {
                continue;
            }
            $age = $d->started_at !== null
                ? (int) round($d->started_at->diffInMinutes(now()))
                : null;
            $rows[] = [
                'site' => $site,
                'deployment_id' => $d->id,
                'trigger' => $d->trigger,
                'when' => $d->started_at?->toIso8601String(),
                'age_label' => $age !== null ? $age.'m' : '—',
                'severity' => $age !== null && $age >= 15 ? 'warning' : 'info',
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function collectFailedLatest($sites): array
    {
        $rows = [];
        foreach ($sites as $site) {
            $latest = SiteDeployment::query()
                ->where('site_id', $site->id)
                ->whereIn('status', [
                    SiteDeployment::STATUS_SUCCESS,
                    SiteDeployment::STATUS_FAILED,
                    SiteDeployment::STATUS_SKIPPED,
                ])
                ->orderByDesc('started_at')
                ->first(['id', 'status', 'finished_at', 'trigger']);
            if ($latest === null || $latest->status !== SiteDeployment::STATUS_FAILED) {
                continue;
            }
            $rows[] = [
                'site' => $site,
                'deployment_id' => $latest->id,
                'trigger' => $latest->trigger,
                'when' => $latest->finished_at?->toIso8601String(),
                'age_label' => $latest->finished_at?->diffForHumans() ?? '—',
                'severity' => 'danger',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['when'], (string) $a['when']));

        return $rows;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function collectStale($sites): array
    {
        $threshold = now()->subDays(max(0, $this->staleDays));
        $rows = [];
        foreach ($sites as $site) {
            $latest = SiteDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', SiteDeployment::STATUS_SUCCESS)
                ->orderByDesc('finished_at')
                ->first(['id', 'finished_at']);
            if ($latest === null || $latest->finished_at === null) {
                continue;
            }
            if ($latest->finished_at->isAfter($threshold)) {
                continue;
            }
            $age = (int) round($latest->finished_at->diffInDays(now()));
            $rows[] = [
                'site' => $site,
                'deployment_id' => $latest->id,
                'trigger' => null,
                'when' => $latest->finished_at->toIso8601String(),
                'age_label' => $age.'d',
                'severity' => 'info',
            ];
        }
        usort($rows, function ($a, $b) {
            $ageA = (int) rtrim($a['age_label'], 'd');
            $ageB = (int) rtrim($b['age_label'], 'd');

            return $ageB - $ageA;
        });

        return $rows;
    }
}
