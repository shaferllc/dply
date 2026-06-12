<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\Concerns\WritesPoolMemberEnv;
use App\Jobs\SyncEnvFromServerJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * "Compare with workers" panel on the site Environment page.
 *
 * A site that belongs to a worker pool is replicated onto every pool member
 * server; the queue workers there need the *same* `.env` the web/main site
 * runs, or background jobs silently behave differently (wrong queue connection,
 * missing API keys, a stale APP_KEY that can't decrypt). This panel lines the
 * main site's env up against each pool member's and highlights drift —
 * keys that differ, keys a worker is missing, and keys only a worker has.
 *
 * Two-speed by design (per the env-cache contract used across this page):
 *   - First render compares the encrypted per-site cache across members. No
 *     SSH, paints instantly, reflects what dply intends each box to hold.
 *   - "Read live from workers" dispatches one {@see SyncEnvFromServerJob} per
 *     member (the same queued SSH read the Sync button uses), refreshing each
 *     cache from the real file on the box. The table re-reads the cache as the
 *     jobs land, so columns update in place — no render-path SSH.
 */
class WorkerEnvComparison extends Component
{
    public string $siteId;

    /** Cleartext is masked by default; reveal resets on navigation away. */
    public bool $reveal = false;

    /** Default to the actionable view: only rows where a worker drifts. */
    public bool $onlyDrift = true;

    /** Set when a live refresh has been dispatched; drives wire:poll. */
    public bool $refreshing = false;

    public function mount(Site $site): void
    {
        Gate::authorize('view', $site);

        $this->siteId = (string) $site->id;
    }

    public function toggleReveal(): void
    {
        $this->reveal = ! $this->reveal;
    }

    public function toggleOnlyDrift(): void
    {
        $this->onlyDrift = ! $this->onlyDrift;
    }

    /**
     * Queue a live `.env` read from every compared box. Each job re-reads the
     * file over SSH and writes it into that site's cache, which this panel
     * renders from — so the columns refresh as the jobs complete.
     */
    public function refreshFromWorkers(): void
    {
        $main = $this->site();
        Gate::authorize('update', $main);

        foreach ($this->comparedSites($main) as $site) {
            if ($site->server?->hostCapabilities()->supportsEnvPushToHost()) {
                SyncEnvFromServerJob::dispatch(
                    (string) $site->id,
                    (string) (auth()->id() ?? ''),
                );
            }
        }

        $this->refreshing = true;
    }

    public function render(DotEnvFileParser $parser): View
    {
        $main = $this->site();
        $columnsSites = $this->comparedSites($main);

        // Which compared boxes have a live env read in flight right now. Drives
        // both the per-column "reading…" pill and whether wire:poll keeps going.
        $syncingIds = ConsoleAction::query()
            ->whereIn('subject_id', $columnsSites->map(fn (Site $s): string => (string) $s->id)->all())
            ->where('subject_type', (new Site)->getMorphClass())
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->pluck('subject_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($this->refreshing && $syncingIds === []) {
            $this->refreshing = false;
        }

        // Parse each column's cache once.
        $colVars = [];
        foreach ($columnsSites as $site) {
            $colVars[(string) $site->id] = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        }
        $mainVars = $colVars[$main->id] ?? [];

        $columns = $columnsSites->map(fn (Site $site): array => [
            'id' => (string) $site->id,
            'label' => $site->server?->name ?: $site->name,
            'is_main' => $site->id === $main->id,
            'role' => $site->server?->pool_role,
            'syncing' => in_array((string) $site->id, $syncingIds, true),
        ])->all();

        // Union of keys across every column, sorted for a stable table.
        $allKeys = [];
        foreach ($colVars as $vars) {
            foreach (array_keys($vars) as $k) {
                $allKeys[$k] = true;
            }
        }
        $allKeys = array_keys($allKeys);
        sort($allKeys);

        $rows = [];
        $driftRowCount = 0;
        $perColumnDrift = array_fill_keys(array_map(fn ($c) => $c['id'], $columns), 0);

        foreach ($allKeys as $key) {
            $mainHas = array_key_exists($key, $mainVars);
            $mainVal = $mainVars[$key] ?? null;
            $cells = [];
            $rowDrift = false;

            foreach ($columns as $col) {
                $vars = $colVars[$col['id']];
                $has = array_key_exists($key, $vars);
                $val = $has ? $vars[$key] : null;

                if ($col['is_main']) {
                    $state = $has ? 'main' : 'main-absent';
                } elseif (! $has) {
                    $state = 'missing';
                } elseif (! $mainHas) {
                    $state = 'extra';
                } elseif ($val !== $mainVal) {
                    $state = 'differ';
                } else {
                    $state = 'match';
                }

                if (in_array($state, ['missing', 'extra', 'differ'], true)) {
                    $rowDrift = true;
                    $perColumnDrift[$col['id']]++;
                }

                $cells[] = [
                    'state' => $state,
                    'display' => $has ? ($this->reveal ? $val : $this->mask((string) $val)) : null,
                ];
            }

            if ($rowDrift) {
                $driftRowCount++;
            }

            $rows[] = ['key' => $key, 'cells' => $cells, 'drift' => $rowDrift];
        }

        $visibleRows = $this->onlyDrift
            ? array_values(array_filter($rows, fn (array $r): bool => $r['drift']))
            : $rows;

        return view('livewire.sites.worker-env-comparison', [
            'columns' => $columns,
            'rows' => $visibleRows,
            'totalKeys' => count($allKeys),
            'driftRowCount' => $driftRowCount,
            'perColumnDrift' => $perColumnDrift,
            'canRefresh' => $main->server?->hostCapabilities()->supportsEnvPushToHost() ?? false,
        ]);
    }

    /** The site being viewed, re-read fresh so cache refreshes are reflected. */
    private function site(): Site
    {
        return Site::query()->with('server')->findOrFail($this->siteId);
    }

    /**
     * The main site followed by each *other* pool member's app site. Returns
     * just the main site when the pool has no peers (the panel won't render in
     * that case, but stay defensive).
     *
     * @return Collection<int, Site>
     */
    private function comparedSites(Site $main): Collection
    {
        $poolId = $main->server?->worker_pool_id;
        if (! $poolId) {
            return collect([$main]);
        }

        $members = Server::query()
            ->where('worker_pool_id', $poolId)
            ->whereKeyNot($main->server_id)
            ->orderBy('created_at')
            ->with(['sites' => fn ($q) => $q])
            ->get();

        $sites = collect([$main]);
        foreach ($members as $member) {
            $appSite = $this->appSite($member);
            if ($appSite && $appSite->id !== $main->id) {
                $appSite->setRelation('server', $member);
                $sites->push($appSite);
            }
        }

        return $sites;
    }

    /**
     * The application site on a pool member — the Laravel app if detected,
     * otherwise the first site. Mirrors {@see WritesPoolMemberEnv::appSite}.
     */
    private function appSite(Server $member): ?Site
    {
        $sites = $member->sites;

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 2).str_repeat('•', max(4, $len - 4)).substr($value, -2);
    }
}
