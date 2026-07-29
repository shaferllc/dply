<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\ErrorEvent;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Tier-1 differentiator (docs/SERVER_LOGS_BILLING.md): cross-link the dply Logs
 * store with the other signals dply already owns — the errors stream, the deploy
 * timeline, uptime incidents — so a customer jumps straight from "this 5xx / this
 * release / this downtime" to the exact log slice that surrounded it. No
 * standalone log tool can do this; it's what turns "I could use Papertrail" into
 * "this is wired into my whole platform."
 *
 * Thin orchestration over {@see LogExplorerQuery::window()/around()}; the org +
 * server tenant scoping and bound params live there.
 */
class ServerLogCorrelator
{
    public function __construct(private LogExplorerQuery $explorer) {}

    /**
     * Logs surrounding a single instant on a server (± seconds each side).
     *
     * @param  array{search?:string,level?:string,source?:string,limit?:int}  $filters
     * @return array{instant:string,from:string,to:string,logs:list<array<string,mixed>>}
     */
    public function aroundInstant(
        Server $server,
        CarbonInterface $instant,
        int $beforeSeconds = 120,
        int $afterSeconds = 120,
        array $filters = [],
    ): array {
        $from = $instant->copy()->subSeconds(max(0, $beforeSeconds));
        $to = $instant->copy()->addSeconds(max(0, $afterSeconds));

        return [
            'instant' => $instant->copy()->utc()->toIso8601String(),
            'from' => $from->copy()->utc()->toIso8601String(),
            'to' => $to->copy()->utc()->toIso8601String(),
            'logs' => $this->explorer->window($server, $from, $to, $filters),
        ];
    }

    /**
     * Logs across an event window (a deploy's started_at..finished_at, a downtime
     * incident's started_at..resolved_at), padded a little on each side so the
     * lead-up and aftermath are visible. The deploy-timeline and uptime-incident
     * UIs call this with the server already in scope and the event's timestamps.
     *
     * @param  array{search?:string,level?:string,source?:string,limit?:int}  $filters
     * @return array{from:string,to:string,logs:list<array<string,mixed>>}
     */
    public function inWindow(
        Server $server,
        CarbonInterface $from,
        CarbonInterface $to,
        int $padSeconds = 30,
        array $filters = [],
    ): array {
        $start = $from->copy()->subSeconds(max(0, $padSeconds));
        $end = $to->copy()->addSeconds(max(0, $padSeconds));

        return [
            'from' => $start->copy()->utc()->toIso8601String(),
            'to' => $end->copy()->utc()->toIso8601String(),
            'logs' => $this->explorer->window($server, $start, $end, $filters),
        ];
    }

    /**
     * The headline "error → logs" jump: the log slice around when an error
     * occurred, on the server it occurred on. Returns null when the error has no
     * owning server (e.g. an app-only error) — the caller hides the affordance.
     *
     * @param  array{search?:string,level?:string,source?:string,limit?:int}  $filters
     * @return array{instant:string,from:string,to:string,logs:list<array<string,mixed>>}|null
     */
    public function forErrorEvent(
        ErrorEvent $error,
        int $beforeSeconds = 120,
        int $afterSeconds = 120,
        array $filters = [],
    ): ?array {
        $serverId = (string) ($error->server_id ?? '');
        if ($serverId === '') {
            return null;
        }

        $server = Server::query()->find($serverId);
        if (! $server instanceof Server) {
            return null;
        }

        $instant = $error->occurred_at ?? $error->created_at ?? Carbon::now();

        return $this->aroundInstant($server, $instant, $beforeSeconds, $afterSeconds, $filters);
    }
}
