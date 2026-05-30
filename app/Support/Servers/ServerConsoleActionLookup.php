<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Request-scoped memo for server {@see ConsoleAction} rows used by the
 * webserver workspace banner, switch revert CTA, and inflight guards.
 *
 * Without this, {@see WebserverWorkspaceViewData} issued three similar
 * SELECTs and {@see WorkspaceManage} added two EXISTS checks on every
 * render — all against the same subject + kind filters.
 */
final class ServerConsoleActionLookup
{
    /**
     * @var array<string, array{
     *     rows: Collection<int, ConsoleAction>,
     *     banner: ?ConsoleAction,
     *     webserver_switch: ?ConsoleAction,
     *     inflight_webserver_switch: bool,
     *     inflight_edge_proxy: bool
     * }>
     */
    private array $cache = [];

    /**
     * @return array{
     *     rows: Collection<int, ConsoleAction>,
     *     banner: ?ConsoleAction,
     *     webserver_switch: ?ConsoleAction,
     *     inflight_webserver_switch: bool,
     *     inflight_edge_proxy: bool
     * }
     */
    public function stateFor(Server $server): array
    {
        $key = (string) $server->id;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $rows = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->whereIn('kind', ['webserver_switch', 'edge_proxy', 'manage_action'])
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $banner = $rows
            ->sortBy(fn (ConsoleAction $action): array => [
                $action->isInFlight() ? 0 : 1,
                -1 * (int) $action->created_at?->getTimestamp(),
            ])
            ->first();

        $switchRows = $rows->where('kind', 'webserver_switch');
        $webserverSwitch = $switchRows->first(fn (ConsoleAction $action): bool => $action->isInFlight())
            ?? $switchRows->sortByDesc(fn (ConsoleAction $action): int => (int) $action->created_at?->getTimestamp())->first();

        if ($banner !== null) {
            $banner->setRelation('subject', $server);
        }
        if ($webserverSwitch !== null) {
            $webserverSwitch->setRelation('subject', $server);
        }

        return $this->cache[$key] = [
            'rows' => $rows,
            'banner' => $banner,
            'webserver_switch' => $webserverSwitch,
            'inflight_webserver_switch' => $rows->contains(
                fn (ConsoleAction $action): bool => $action->kind === 'webserver_switch'
                    && $action->isInFlight(),
            ),
            'inflight_edge_proxy' => $rows->contains(
                fn (ConsoleAction $action): bool => $action->kind === 'edge_proxy'
                    && $action->isInFlight(),
            ),
        ];
    }

    public function bannerFor(Server $server): ?ConsoleAction
    {
        return $this->stateFor($server)['banner'];
    }

    public function webserverSwitchFor(Server $server): ?ConsoleAction
    {
        return $this->stateFor($server)['webserver_switch'];
    }

    public function hasInflightWebserverSwitch(Server $server): bool
    {
        return $this->stateFor($server)['inflight_webserver_switch'];
    }

    public function hasInflightEdgeProxy(Server $server): bool
    {
        return $this->stateFor($server)['inflight_edge_proxy'];
    }

    public function forget(Server $server): void
    {
        unset($this->cache[(string) $server->id]);
    }

    public function shouldRefreshServerMeta(Server $server): bool
    {
        $banner = $this->bannerFor($server);
        if ($banner === null) {
            return false;
        }

        if ($banner->isInFlight() && ! $banner->isStale()) {
            return true;
        }

        return $banner->finished_at !== null
            && $banner->finished_at->gt(now()->subSeconds(12));
    }
}
