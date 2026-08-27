<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Jobs\CollectSiteQueueJobsJob;
use App\Jobs\RunSiteQueueCanaryJob;
use App\Jobs\SetUpSiteQueueingJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\SeedsSiteConsoleActions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\QueueInsightsInstaller;
use App\Support\Sites\QueueJobPayload;
use App\Support\Sites\QueueWorkerClassifier;
use App\Support\Sites\SiteDaemonAdvisor;
use App\Support\Sites\SiteQueueConfiguration;
use App\Support\Sites\SiteQueueReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Is my queue healthy?" — one page, whether the work runs on this box or on an
 * attached pool.
 *
 * Reads only: depth history comes from the five-minute per-server sweep
 * ({@see CollectServerQueueSnapshotsJob}), never from SSH in the render path.
 * Refresh queues that same job rather than connecting inline, so the page
 * behaves identically whether you opened it or the scheduler did.
 *
 * Queue-shaped Supervisor programs belong here and are excluded from Workers:
 * one process, one page, one set of controls.
 */
#[Layout('layouts.app')]
class WorkspaceQueue extends Component
{
    use DispatchesToastNotifications;
    use SeedsSiteConsoleActions;

    public Server $server;

    public Site $site;

    /** Hours of history the depth table summarises. */
    public int $window_hours = 24;

    /** Which section of the Queue workspace is showing. */
    public string $queue_workspace_tab = 'queues';

    /** Queue whose waiting jobs the Jobs tab is showing. */
    public string $inspect_queue = '';

    public bool $showCreate = false;

    /**
     * Where the worker runs: 'server' for a Supervisor program on this box, or
     * a pool id for a managed worker server. The same question — "who drains
     * this queue" — with two honest answers, rather than one page for each.
     */
    public string $new_placement = 'server';

    /** Queue name the new worker drains. */
    public string $new_queue = 'default';

    /** Blank uses the app's default connection from config/queue.php. */
    public string $new_connection = '';

    public int $new_processes = 1;

    public int $new_tries = 3;

    public int $new_timeout = 60;

    public int $new_sleep = 3;

    public int $new_memory = 128;

    /** Seconds before a failed job is retried. Blank omits the flag. */
    public string $new_backoff = '';

    /** Recycle after N jobs — bounds a slow memory leak. Blank omits it. */
    public string $new_max_jobs = '';

    /** Seconds a worker sleeps between jobs. Blank omits it. */
    public string $new_rest = '';

    public int $new_max_time = 3600;

    /** Drain and exit instead of waiting — for burst work, not steady queues. */
    public bool $new_stop_when_empty = false;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);

        if ($site->server_id !== $server->id) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
    }

    /**
     * Queue the same sweep the scheduler runs. Deliberately not an inline SSH:
     * PHP's 30s limit makes that a coin-flip on a slow box, and the page would
     * then show something the scheduled path never produces.
     */
    public function refreshSnapshot(): void
    {
        $this->authorize('update', $this->site);

        CollectServerQueueSnapshotsJob::dispatch((string) $this->server->id);

        $this->toastSuccess(__('Collecting queue depth — the table updates when it lands.'));
    }

    public function openCreate(): void
    {
        $this->authorize('update', $this->site);
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
    }

    /**
     * Create a worker for a queue.
     *
     * "Create a queue" is the ask, but a queue is not a resource that exists to
     * be created — it is a name jobs are pushed to, and it springs into being
     * the moment something enqueues. What you actually create is the WORKER
     * that drains it, so that is what this makes: a Supervisor program running
     * `queue:work --queue=<name>` for this site, written through the same
     * provisioner the Workers page uses.
     */
    public function createWorker(SupervisorProvisioner $provisioner, WorkerPoolManager $pools): void
    {
        $this->authorize('update', $this->site);

        // A managed worker server already runs this app's queues; adding a
        // worker there means one more machine draining them, not a Supervisor
        // program on a box the site does not live on.
        if ($this->new_placement !== 'server') {
            $this->addPoolWorker($this->new_placement, $pools);

            return;
        }

        $validated = $this->validate([
            // Laravel allows commas for priority order; the rest is the
            // conservative set every driver accepts as a queue name.
            'new_queue' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-:.,]+$/'],
            'new_connection' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-]*$/'],
            'new_processes' => ['required', 'integer', 'min:1', 'max:50'],
            'new_tries' => ['required', 'integer', 'min:1', 'max:100'],
            'new_timeout' => ['required', 'integer', 'min:5', 'max:3600'],
            'new_sleep' => ['required', 'integer', 'min:0', 'max:300'],
            'new_memory' => ['required', 'integer', 'min:32', 'max:4096'],
            'new_max_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'new_backoff' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'new_max_jobs' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'new_rest' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ], attributes: ['new_queue' => __('queue name')]);

        $queue = trim($validated['new_queue']);
        $directory = rtrim((string) $this->site->effectiveEnvDirectory(), '/');

        if ($directory === '') {
            $this->toastError(__('This site has no application directory yet — deploy it first.'));

            return;
        }

        $slug = Str::slug($this->site->name.'-queue-'.$queue);

        if (SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $slug)->exists()) {
            $this->addError('new_queue', __('A worker for that queue already exists on this server.'));

            return;
        }

        $command = $this->buildWorkerCommand($queue, $validated);

        $program = SupervisorProgram::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $this->site->id,
            'slug' => $slug,
            'program_type' => 'queue',
            'command' => $command,
            'directory' => $directory,
            'user' => $this->site->effectiveSystemUser($this->server) ?: 'dply',
            'numprocs' => $validated['new_processes'],
            'is_active' => true,
        ]);

        SupervisorDaemonAudit::log($this->server->fresh(), $program, 'create_queue_worker', ['queue' => $queue]);

        try {
            // Write the conf and reread/update, so the worker is running when
            // the modal closes rather than "created" but absent from the box.
            $provisioner->syncProgram($this->server->fresh(), (string) $program->id);
            $this->toastSuccess(__('Worker created for the :queue queue.', ['queue' => $queue]));
        } catch (\Throwable $e) {
            $this->toastError(__('Worker saved, but Supervisor did not pick it up: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));
        }

        $this->showCreate = false;
        $this->new_queue = 'default';
    }

    /**
     * The `queue:work` command line for the chosen options.
     *
     * Optional flags are OMITTED rather than sent with a default: `--backoff=0`
     * is not the same as no backoff to every driver, and a command line that
     * only carries what you set is one you can read back and recognise.
     *
     * @param  array<string, mixed>  $o
     */
    private function buildWorkerCommand(string $queue, array $o): string
    {
        $parts = ['php artisan queue:work'];

        // Connection is positional and must precede the flags.
        if (trim((string) ($o['new_connection'] ?? '')) !== '') {
            $parts[] = escapeshellarg(trim((string) $o['new_connection']));
        }

        $parts[] = sprintf("--queue='%s'", $queue);
        $parts[] = '--sleep='.(int) $o['new_sleep'];
        $parts[] = '--timeout='.(int) $o['new_timeout'];
        $parts[] = '--tries='.(int) $o['new_tries'];
        $parts[] = '--memory='.(int) $o['new_memory'];

        // --max-time keeps a worker from living forever on stale code: it exits
        // and Supervisor restarts it, which is how a deploy's new code reaches
        // the queue at all. 0 means "never exit" — allowed, but deliberate.
        if ((int) $o['new_max_time'] > 0) {
            $parts[] = '--max-time='.(int) $o['new_max_time'];
        }

        foreach (['new_backoff' => '--backoff', 'new_max_jobs' => '--max-jobs', 'new_rest' => '--rest'] as $field => $flag) {
            if (trim((string) ($o[$field] ?? '')) !== '') {
                $parts[] = $flag.'='.trim((string) $o[$field]);
            }
        }

        if ($this->new_stop_when_empty) {
            $parts[] = '--stop-when-empty';
        }

        return implode(' ', $parts);
    }

    /**
     * Add one machine to a managed worker pool.
     *
     * Scaling, not program-creation: the pool already runs this app and drains
     * its queues, so "another worker there" is another replica. Going through
     * WorkerPoolManager keeps one owner of pool size — the Worker Servers page
     * and this page cannot disagree about desired_count.
     */
    private function addPoolWorker(string $poolId, WorkerPoolManager $pools): void
    {
        $pool = $this->site->attachedWorkerPools()->firstWhere('id', $poolId);

        if ($pool === null) {
            $this->addError('new_placement', __('That worker server is not attached to this site.'));

            return;
        }

        $desired = (int) $pool->desired_count + 1;
        $max = (int) $pool->max_size;

        if ($max > 0 && $desired > $max) {
            $this->addError('new_placement', __('That pool is already at its maximum of :max.', ['max' => $max]));

            return;
        }

        try {
            $pools->setDesiredCount($pool, $desired);
            $this->toastSuccess(__('Scaling :pool to :n worker(s).', ['pool' => $pool->name, 'n' => $desired]));
            $this->showCreate = false;
        } catch (\Throwable $e) {
            $this->toastError(Str::limit($e->getMessage(), 300));
        }
    }

    public function startWorker(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->controlWorker($id, 'start', fn (SupervisorProgram $p) => $provisioner->startProgramGroup($this->server->fresh(), (string) $p->id));
    }

    public function stopWorker(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->controlWorker($id, 'stop', fn (SupervisorProgram $p) => $provisioner->stopProgramGroup($this->server->fresh(), (string) $p->id));
    }

    public function restartWorker(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->controlWorker($id, 'restart', fn (SupervisorProgram $p) => $provisioner->restartProgramGroup($this->server->fresh(), (string) $p->id));
    }

    public function deleteWorker(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $program = $this->ownedProgram($id);

        if ($program === null) {
            return;
        }

        try {
            // Conf file first, row second. The other order leaves an orphan
            // worker on the box still consuming jobs, with nothing in dply left
            // pointing at it to stop it.
            $provisioner->deleteConfigFile($this->server->fresh(), (string) $program->id);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not remove it from Supervisor: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

            return;
        }

        SupervisorDaemonAudit::log($this->server->fresh(), $program, 'delete_queue_worker', []);
        $program->delete();
        $this->toastSuccess(__('Worker removed.'));
    }

    /**
     * Every control goes through here so a program id from another site (or
     * another server) can never be started, stopped or deleted from this page.
     */
    private function controlWorker(string $id, string $action, callable $run): void
    {
        $this->authorize('update', $this->site);

        $program = $this->ownedProgram($id);

        if ($program === null) {
            return;
        }

        try {
            $out = (string) $run($program);
            SupervisorDaemonAudit::log($this->server->fresh(), $program, $action.'_queue_worker', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(Str::limit(trim($out) !== '' ? $out : __('Done.'), 300));
        } catch (\Throwable $e) {
            $this->toastError(Str::limit($e->getMessage(), 300));
        }
    }

    private function ownedProgram(string $id): ?SupervisorProgram
    {
        $program = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->first();

        if ($program === null || ! QueueWorkerClassifier::isQueueWorker($program->command)) {
            $this->toastError(__('That worker is not managed from this page.'));

            return null;
        }

        return $program;
    }

    /**
     * Move this site off `sync` onto a real driver.
     *
     * Runs the same stepwise flow the guided setup uses: write the env, push it,
     * clear the config cache, ensure a worker, and turn on restart-after-deploy.
     * The config clear is not tidy-up — with a warm cache the app keeps the old
     * QUEUE_CONNECTION and every later step passes while jobs still run inline,
     * which is a success indistinguishable from the bug.
     */
    public function switchQueueDriver(): void
    {
        $this->authorize('update', $this->site);

        $driver = SiteQueueConfiguration::suggestedDriverFor($this->site);

        if ($driver === null) {
            // No offer we can honour. Better to say so than to write a driver
            // the site has nothing behind.
            $this->toastError(__('This site has no Redis or database resource to queue onto. Attach one under Resources first.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('queue_setup', __('Switching to :d', ['d' => $driver]));

        SetUpSiteQueueingJob::dispatch((string) $run->id, (string) $this->site->id, $driver, (string) auth()->id() ?: null);

        $this->toastSuccess(__('Switching this site to the :d queue — progress shows in the console above.', ['d' => $driver]));
    }

    /** The driver the switch button would use, or null when there is nothing to offer. */
    public function suggestedQueueDriver(): ?string
    {
        return SiteQueueConfiguration::suggestedDriverFor($this->site);
    }

    /**
     * Put a job in and watch it come out.
     *
     * The only check that covers the whole chain — driver, connection, a live
     * worker, and the config the app actually booted with. Everything cheaper
     * passes while jobs never run.
     */
    public function runCanary(string $queue = ''): void
    {
        $this->authorize('update', $this->site);

        // Test the queue a worker actually drains. Hardcoding 'default' meant
        // the canary could sit forever on a queue nothing consumes while the
        // real one was fine — a false negative that looks exactly like a broken
        // queue.
        $queue = $queue !== '' ? $queue : $this->firstDrainedQueue();

        $run = method_exists($this, 'seedQueuedConsoleAction')
            ? $this->seedQueuedConsoleAction('queue_canary', __('Testing the queue'))
            : null;

        if ($run === null) {
            $this->toastError(__('Could not start the test.'));

            return;
        }

        RunSiteQueueCanaryJob::dispatch((string) $run->id, (string) $this->site->id, $queue);

        $this->toastSuccess(__('Testing — progress shows in the console above.'));
    }

    /**
     * The queue the site's first active worker drains, or 'default'.
     *
     * A worker with no `--queue=` takes the app's default queue, which only the
     * app can resolve — 'default' is the right guess for every framework dply
     * supports.
     */
    private function firstDrainedQueue(): string
    {
        $worker = $this->workers()->firstWhere('is_active', true) ?? $this->workers()->first();

        if ($worker === null) {
            return 'default';
        }

        // A multi-queue worker drains its list in priority order; the first is
        // the one it reaches for.
        $declared = QueueWorkerClassifier::queueNameFrom($worker->command) ?? 'default';

        return trim(explode(',', $declared)[0]) ?: 'default';
    }

    /**
     * Opt this site into the in-app queue agent.
     *
     * Stored on meta, not a column: this is a preference about dply's own
     * tooling rather than a property of the site's runtime. Takes effect on the
     * next deploy, when {@see QueueInsightsInstaller}
     * requires the package into the release — nothing is installed behind the
     * operator's back at the moment they flip a switch.
     */
    public function toggleQueueAgent(): void
    {
        $this->authorize('update', $this->site);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $enabled = ! (bool) data_get($meta, 'queue_insights.enabled', false);
        $meta['queue_insights'] = ['enabled' => $enabled];

        $this->site->forceFill(['meta' => $meta])->save();

        $this->toastSuccess($enabled
            ? __('The queue agent installs on the next deploy.')
            : __('The queue agent will not be installed. It stays in place until the next deploy removes it.'));
    }

    public function queueAgentEnabled(): bool
    {
        return (bool) data_get($this->site->meta, 'queue_insights.enabled', false);
    }

    /**
     * Turn on restart-after-deploy.
     *
     * The one readiness failure dply can fix with a column write: the flag
     * already exists and {@see SupervisorDeployRestarter}
     * already honours it — it was only ever reachable from the Pipeline page,
     * two clicks from where the consequence shows up.
     */
    public function enableDeployRestart(): void
    {
        $this->authorize('update', $this->site);

        $this->site->forceFill(['restart_supervisor_programs_after_deploy' => true])->save();

        $this->toastSuccess(__('Workers will restart after each deploy.'));
    }

    /**
     * Ask the box what is waiting on a queue.
     *
     * Queued and cached rather than read inline: this is SSH work, and SSH in
     * the render path is a 30-second timeout waiting to happen.
     */
    public function inspectQueue(string $queue): void
    {
        $this->authorize('view', $this->site);

        $this->inspect_queue = $queue;
        $this->queue_workspace_tab = 'jobs';

        CollectSiteQueueJobsJob::dispatch((string) $this->site->id, $queue);
    }

    /**
     * The cached read for the inspected queue, parsed into rows.
     *
     * @return array{jobs: list<QueueJobPayload>, driver: ?string, truncated: bool, error: ?string, read_at: ?string}|null
     */
    public function inspectedJobs(): ?array
    {
        if ($this->inspect_queue === '') {
            return null;
        }

        $cached = Cache::get(CollectSiteQueueJobsJob::cacheKey((string) $this->site->id, $this->inspect_queue));

        if (! is_array($cached)) {
            return null;
        }

        $now = strtotime((string) ($cached['read_at'] ?? 'now')) ?: null;

        return [
            'jobs' => array_values(array_filter(array_map(
                static fn (string $json): ?QueueJobPayload => QueueJobPayload::fromJson($json, $now),
                array_map('strval', (array) ($cached['jobs'] ?? [])),
            ))),
            'driver' => $cached['driver'] ?? null,
            'truncated' => (bool) ($cached['truncated'] ?? false),
            'error' => $cached['error'] ?? null,
            'read_at' => $cached['read_at'] ?? null,
        ];
    }

    /** Supervisor programs on this site that actually consume jobs. */
    public function workers(): Collection
    {
        return SupervisorProgram::query()
            ->where('site_id', $this->site->id)
            ->orderBy('slug')
            ->get()
            ->filter(fn (SupervisorProgram $program): bool => QueueWorkerClassifier::isQueueWorker($program->command))
            ->values();
    }

    public function render(): View
    {
        $since = now()->subHours(max(1, $this->window_hours));

        $snapshots = SiteQueueSnapshot::query()
            ->where('site_id', $this->site->id)
            ->where('captured_at', '>=', $since)
            ->orderByDesc('captured_at')
            ->limit(2000)
            ->get();

        // One card per queue: newest reading, plus the window's peak so a
        // backlog that has already drained is still visible. A queue seen in
        // history but with no worker now is the interesting case, so the list
        // is built from BOTH sources rather than from the workers alone.
        $byQueue = $snapshots->groupBy('queue')->map(function (Collection $rows): array {
            $latest = $rows->first();

            return [
                'latest' => $latest,
                'peak_pending' => (int) $rows->max('pending'),
                'samples' => $rows->count(),
                'trend' => $rows->sortBy('captured_at')->pluck('pending')->all(),
            ];
        });

        $workers = $this->workers();

        foreach ($workers as $worker) {
            foreach (explode(',', QueueWorkerClassifier::queueNameFrom($worker->command) ?? 'default') as $queue) {
                $queue = trim($queue);

                if ($queue !== '' && ! $byQueue->has($queue)) {
                    // Declared by a worker but never sampled — the sweep has not
                    // run yet, or it could not read this site.
                    $byQueue->put($queue, ['latest' => null, 'peak_pending' => 0, 'samples' => 0, 'trend' => []]);
                }
            }
        }

        $pools = $this->site->attachedWorkerPools();

        return view('livewire.sites.workspace-queue', [
            // The check nothing on the box can make: a worker against `sync`
            // consumes nothing while every other reading looks healthy.
            'queueConfigWarning' => SiteQueueConfiguration::for($this->site)->warning(),
            // The banner partial reads both unconditionally. Omitting them is an
            // undefined-variable warning locally and an ErrorException — a 500 —
            // in production, where Laravel promotes warnings.
            'sectionConsoleActionKinds' => $kinds = (array) config('console_actions.section_kinds.queue', []),
            'sectionConsoleActionRun' => $kinds === []
                ? null
                : ConsoleAction::query()
                    ->forSubject($this->site)
                    ->whereIn('kind', $kinds)
                    ->notDismissed()
                    ->orderByDesc('created_at')
                    ->first(),
            'readinessChecks' => SiteQueueReadiness::checks($this->site, $workers, $pools, $snapshots->first()),
            // Managed worker servers are part of "is my queue healthy", so they
            // render here rather than only under Worker Servers.
            'pools' => $pools,
            // Counts for the at-a-glance strip. Computed from the SAME sets the
            // tabs render, so a number can never disagree with the list beneath
            // it.
            'queueStats' => [
                'queues' => $byQueue->count(),
                'pending' => (int) $byQueue->sum(fn (array $q): int => (int) ($q['latest']->pending ?? 0)),
                'failed' => (int) ($snapshots->first()?->failed_total ?? 0),
                'workers' => $workers->where('is_active', true)->count(),
                'machines' => (int) $pools->sum('desired_count'),
            ],
            'queueSuggestions' => SiteDaemonAdvisor::onlyForSurface(
                SiteDaemonAdvisor::suggestions($this->site),
                SiteDaemonAdvisor::SURFACE_QUEUE,
            ),
            'queues' => $byQueue->sortKeys(),
            'workers' => $workers,
            'failedTotal' => $snapshots->first()?->failed_total,
            'lastCapturedAt' => $snapshots->first()?->captured_at,
        ]);
    }
}
