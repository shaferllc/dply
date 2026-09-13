<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Jobs\SetUpSiteQueueingJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Services\WorkerPools\WorkerDaemonBackend;
use Illuminate\Support\Collection;

/**
 * Which queue workers a connection switch moves, stops and starts.
 *
 * One backend per site, so a switch stops the workers that cannot read the new
 * connection and makes sure one worker can. Stopped programs are kept, only
 * deactivated — switching back reactivates them instead of creating another.
 * Queue workers still running as systemd units move to Supervisor first, and
 * are planned as the programs they become. The confirm modal and
 * {@see SetUpSiteQueueingJob} both read this, so what the operator agrees to
 * is what runs.
 */
final class QueueWorkerPlan
{
    /**
     * @param  Collection<int, SupervisorProgram>  $stop
     * @param  Collection<int, SupervisorProgram>  $start  inactive programs to bring back
     * @param  bool  $createDefault  nothing can drain the connection and nothing can be reactivated
     * @param  Collection<int, SiteProcess>  $move  systemd units that become Supervisor programs first
     */
    public function __construct(
        public readonly string $connection,
        public readonly Collection $stop,
        public readonly Collection $start,
        public readonly bool $createDefault,
        public readonly Collection $move = new Collection,
    ) {}

    /**
     * @param  bool|null  $fleetDrains  whether dply's servers take the jobs;
     *                                  null reads it from the site's fleets, a bool previews a choice
     */
    public static function for(Site $site, string $connection, ?bool $fleetDrains = null): self
    {
        // Jobs run inline on sync; workers neither help nor hurt.
        if ($connection === 'sync') {
            return new self($connection, collect(), collect(), false);
        }

        $backend = app(WorkerDaemonBackend::class);
        $move = self::unitsToMove($site, $backend);

        // Moving units are planned as the programs ensure() will write for them;
        // they come first so they win over a stale row with the same slug.
        $programs = $move
            ->map(fn (SiteProcess $p): SupervisorProgram => (new SupervisorProgram)->forceFill([
                'slug' => $backend->programSlug($site, $p),
                'command' => (string) $p->command,
                'is_active' => true,
            ]))
            ->concat(SupervisorProgram::query()->where('site_id', $site->id)->get())
            ->filter(fn (SupervisorProgram $p): bool => QueueWorkerClassifier::isQueueWorker($p->command))
            ->unique('slug')
            ->values();

        $active = $programs->where('is_active', true);

        // dply's servers take the jobs: every worker on this server that would
        // also read the dply queue stops, or the two would split the queue.
        if ($connection === 'dply' && ($fleetDrains ?? self::fleetDrains($site))) {
            return new self(
                $connection,
                $active->filter(fn (SupervisorProgram $p): bool => self::drains((string) $p->command, $connection))->values(),
                collect(),
                false,
                $move,
            );
        }

        // On redis, Horizon is the one drainer when the site has it: it balances
        // the redis queues itself, so a queue:work beside it only doubles up.
        // This is what makes redis → dply → redis land back where it started.
        $horizon = $connection === 'redis'
            ? $programs->filter(fn (SupervisorProgram $p): bool => str_contains((string) $p->command, 'horizon'))->sortByDesc('is_active')->first()
            : null;

        if ($horizon !== null) {
            return new self(
                $connection,
                $active->reject(fn (SupervisorProgram $p): bool => $p->slug === $horizon->slug)->values(),
                $horizon->is_active ? collect() : collect([$horizon]),
                false,
                $move,
            );
        }

        $stop = $active->reject(fn (SupervisorProgram $p): bool => self::drains((string) $p->command, $connection))->values();

        if ($active->contains(fn (SupervisorProgram $p): bool => self::drains((string) $p->command, $connection))) {
            return new self($connection, $stop, collect(), false, $move);
        }

        $revive = $programs->where('is_active', false)
            ->filter(fn (SupervisorProgram $p): bool => self::drains((string) $p->command, $connection))
            ->first();

        return $revive !== null
            ? new self($connection, $stop, collect([$revive]), false, $move)
            : new self($connection, $stop, collect(), true, $move);
    }

    /** Whether a worker running $command takes jobs from $connection. */
    public static function drains(string $command, string $connection): bool
    {
        if (str_contains($command, 'horizon')) {
            return $connection === 'redis';
        }

        // `queue:work redis` pins a connection; a bare `queue:work` reads the
        // app's default, which is whatever the switch just set.
        if (preg_match('/queue:(?:work|listen)\s+([a-z0-9_][a-z0-9_-]*)/i', $command, $m) === 1) {
            return $m[1] === $connection;
        }

        return true;
    }

    /**
     * A fleet only counts once it can start workers — one still waiting on its
     * first image drains nothing, so this server keeps its workers until then.
     *
     * ponytail: any active fleet stops every box worker; match per queue when a
     * site runs a fleet for some queues and box workers for others.
     */
    private static function fleetDrains(Site $site): bool
    {
        return ManagedQueueFleet::query()
            ->where('status', ManagedQueueFleet::STATUS_ACTIVE)
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->whereHas('namespace', fn ($q) => $q->where('site_id', $site->id))
            ->exists();
    }

    public function changesAnything(): bool
    {
        return $this->move->isNotEmpty() || $this->stop->isNotEmpty() || $this->start->isNotEmpty() || $this->createDefault;
    }

    /**
     * Queue workers belong under Supervisor, where this page and deploy
     * restarts manage them. ensure() moves every non-web unit, not only the
     * queue workers, so when any queue worker is a unit all of them are listed.
     *
     * @return Collection<int, SiteProcess>
     */
    private static function unitsToMove(Site $site, WorkerDaemonBackend $backend): Collection
    {
        if ($backend->backendFor($site) === WorkerPool::PM_SUPERVISOR) {
            return collect();
        }

        $units = $site->processes()
            ->where('is_active', true)
            ->where('type', '!=', SiteProcess::TYPE_WEB)
            ->get()
            ->filter(fn (SiteProcess $p): bool => trim((string) $p->command) !== '')
            ->values();

        return $units->contains(fn (SiteProcess $p): bool => QueueWorkerClassifier::isQueueWorker($p->command))
            ? $units
            : collect();
    }
}
