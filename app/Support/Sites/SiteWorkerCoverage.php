<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use Illuminate\Support\Collection;

/**
 * Answers "is a queue / Horizon worker actually running for this site, and
 * where?" — across EVERY server that could drain the site's queue, not just the
 * box the site is hosted on.
 *
 * A worker can live in three places:
 *   1. The site's own server, as a SupervisorProgram (server-wide or site-scoped).
 *   2. The site's own server, as a SiteProcess (materialised into a systemd unit).
 *   3. A SEPARATE worker server on the same private network — a worker-pool
 *      member replicating the app — running the queue daemon as a SiteProcess.
 *      The daemon connects to the same Redis/DB over the VPC, so it drains this
 *      site's queue even though it lives on another box.
 *
 * Both the deploy-pipeline "queue:restart without a running worker" warning and
 * the site Resources roll-up read from here so they agree on what counts.
 */
final class SiteWorkerCoverage
{
    /** @var array<string, Collection<int, array<string, mixed>>> */
    private static array $cache = [];

    /** Command substrings / process types that indicate a queue worker. */
    private const QUEUE_TYPES = ['queue', 'worker', 'horizon'];

    private const QUEUE_NEEDLES = ['queue:work', 'queue:listen', 'horizon'];

    private const HORIZON_TYPES = ['horizon'];

    private const HORIZON_NEEDLES = ['horizon'];

    /**
     * Every worker process that could keep this site's queue / Horizon alive,
     * normalised across both backing stores and every relevant server.
     *
     * @return Collection<int, array{
     *     name: string,
     *     type: string,
     *     command: string,
     *     source: string,
     *     server_id: string,
     *     server_name: string,
     *     off_box: bool,
     *     instances: int,
     *     active: bool
     * }>
     */
    public static function workers(Site $site): Collection
    {
        if (isset(self::$cache[$site->id])) {
            return self::$cache[$site->id];
        }

        $ownServer = $site->server;
        if (! $ownServer instanceof Server) {
            return self::$cache[$site->id] = collect();
        }

        $rows = collect();

        // (1) + (2) — the site's own server. Supervisor programs scoped to this
        // site or server-wide, plus the site's own SiteProcesses.
        $ownServer->supervisorPrograms()
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->get(['slug', 'program_type', 'command', 'numprocs', 'is_active'])
            ->each(function (SupervisorProgram $p) use ($rows, $ownServer): void {
                $rows->push(self::fromSupervisor($p, $ownServer, offBox: false));
            });

        $site->processes()
            ->get(['type', 'name', 'command', 'scale', 'is_active'])
            ->each(function (SiteProcess $p) use ($rows, $ownServer): void {
                $rows->push(self::fromProcess($p, $ownServer, offBox: false));
            });

        // (3) — peer worker servers on the same private network / worker pool.
        // Any active queue/Horizon worker there drains the shared queue, so we
        // count every worker process on the peer regardless of which app site
        // it nominally belongs to.
        foreach (self::peerWorkerServers($site, $ownServer) as $peer) {
            $peer->supervisorPrograms()
                ->get(['slug', 'program_type', 'command', 'numprocs', 'is_active'])
                ->each(function (SupervisorProgram $p) use ($rows, $peer): void {
                    $rows->push(self::fromSupervisor($p, $peer, offBox: true));
                });

            SiteProcess::query()
                ->whereIn('site_id', $peer->sites()->pluck('id'))
                ->get(['type', 'name', 'command', 'scale', 'is_active'])
                ->each(function (SiteProcess $p) use ($rows, $peer): void {
                    $rows->push(self::fromProcess($p, $peer, offBox: true));
                });
        }

        return self::$cache[$site->id] = $rows->values();
    }

    /** True when some ACTIVE worker anywhere drains this site's queue. */
    public static function coversQueue(Site $site): bool
    {
        return self::workers($site)
            ->filter(fn (array $w): bool => $w['active'])
            ->contains(fn (array $w): bool => self::matches($w, self::QUEUE_TYPES, self::QUEUE_NEEDLES));
    }

    /** True when some ACTIVE Horizon worker anywhere serves this site. */
    public static function coversHorizon(Site $site): bool
    {
        return self::workers($site)
            ->filter(fn (array $w): bool => $w['active'])
            ->contains(fn (array $w): bool => self::matches($w, self::HORIZON_TYPES, self::HORIZON_NEEDLES));
    }

    /**
     * Worker servers — other than the site's own — that share the site's
     * private network or worker pool, and so can run its queue daemon.
     *
     * @return Collection<int, Server>
     */
    public static function peerWorkerServers(Site $site, Server $ownServer): Collection
    {
        $peers = collect();

        // Same private network, same organization.
        if ($ownServer->private_network_id !== null) {
            $peers = $peers->concat(
                Server::query()
                    ->where('organization_id', $ownServer->organization_id)
                    ->where('private_network_id', $ownServer->private_network_id)
                    ->whereKeyNot($ownServer->id)
                    ->get()
            );
        }

        // Worker-pool members (the source server + its clones).
        if ($ownServer->worker_pool_id !== null) {
            $peers = $peers->concat(
                Server::query()
                    ->where('worker_pool_id', $ownServer->worker_pool_id)
                    ->whereKeyNot($ownServer->id)
                    ->get()
            );
        }

        return $peers->unique('id')->values();
    }

    /**
     * @param  array<string, mixed>  $worker
     * @param  list<string>  $types
     * @param  list<string>  $needles
     */
    private static function matches(array $worker, array $types, array $needles): bool
    {
        if (in_array(strtolower((string) $worker['type']), $types, true)) {
            return true;
        }

        $command = strtolower((string) $worker['command']);
        foreach ($needles as $needle) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, type: string, command: string, source: string, server_id: string, server_name: string, off_box: bool, instances: int, active: bool}
     */
    private static function fromSupervisor(SupervisorProgram $p, Server $server, bool $offBox): array
    {
        return [
            'name' => (string) $p->slug,
            'type' => (string) $p->program_type,
            'command' => (string) $p->command,
            'source' => 'Supervisor',
            'server_id' => (string) $server->id,
            'server_name' => (string) $server->name,
            'off_box' => $offBox,
            'instances' => max(1, (int) $p->numprocs),
            'active' => (bool) $p->is_active,
        ];
    }

    /**
     * @return array{name: string, type: string, command: string, source: string, server_id: string, server_name: string, off_box: bool, instances: int, active: bool}
     */
    private static function fromProcess(SiteProcess $p, Server $server, bool $offBox): array
    {
        return [
            'name' => (string) ($p->name !== null && $p->name !== '' ? $p->name : $p->type),
            'type' => (string) $p->type,
            'command' => (string) $p->command,
            'source' => 'systemd',
            'server_id' => (string) $server->id,
            'server_name' => (string) $server->name,
            'off_box' => $offBox,
            'instances' => max(1, (int) $p->scale),
            'active' => (bool) $p->is_active,
        ];
    }
}
