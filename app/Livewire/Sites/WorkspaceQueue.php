<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Jobs\CollectSiteFailedJobsJob;
use App\Jobs\CollectSiteJobClassesJob;
use App\Jobs\CollectSiteQueueJobsJob;
use App\Jobs\ControlWorkerDaemonJob;
use App\Jobs\DispatchSiteTestJobJob;
use App\Jobs\ReadSiteQueueJobPayloadJob;
use App\Jobs\RunSiteQueueCanaryJob;
use App\Jobs\SetUpSiteQueueingJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\SeedsSiteConsoleActions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteQueueJobRun;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorDeployRestarter;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\QueueInsightsInstaller;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Support\Sites\QueueJobPayload;
use App\Support\Sites\QueueWorkerClassifier;
use App\Support\Sites\QueueWorkerCommand;
use App\Support\Sites\SiteDaemonAdvisor;
use App\Support\Sites\SiteQueueAlertRules;
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

    /**
     * Which tense of Activity is showing: waiting, failed or history.
     *
     * One panel rather than three tabs — waiting, failed and finished are the
     * same question about the same jobs, and splitting them at the top level
     * meant correlating three lists by memory.
     */
    public string $activity_view = 'waiting';

    /** Queue whose waiting jobs the Jobs tab is showing. */
    public string $inspect_queue = '';

    /** Substring filter over the job catalogue — an app with 300 jobs needs one. */
    public string $catalog_filter = '';

    /** Job class the confirm modal is asking about, if any. */
    public string $confirm_class = '';

    /** Bulk failed-job action awaiting confirmation: 'retry_all' or 'flush'. */
    public string $failed_action = '';

    /** Supervisor program being edited, if any. */
    public string $edit_worker_id = '';

    /** Raw command, shown and editable under Advanced while editing. */
    public string $edit_command = '';

    /** History row expanded to show its detail, by run id. */
    public string $history_open = '';

    /** Job whose payload was explicitly requested, by envelope uuid. */
    public string $payload_uuid = '';

    /** Queue whose alert rules are open, or '' for the site defaults. */
    public string $alert_queue = '';

    public bool $alerts_enabled = true;

    /** Blank disables the rule — a threshold dply invented is worse than none. */
    public string $alert_pending_over = '';

    public int $alert_sustained_minutes = 10;

    public string $alert_oldest_over_s = '';

    public bool $alert_no_worker = true;

    /** Queue the purge modal is asking about. */
    public string $purge_queue = '';

    /** Typed confirmation for the purge — must match {@see $purge_queue}. */
    public string $purge_confirm = '';

    /** Constructor arguments for that job, as a JSON array: [12, "now"]. */
    public string $dispatch_args = '';

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

        $run = $this->seedQueuedConsoleAction('queue_canary', __('Testing the queue'));

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

        // A token that survives toggling off and on: rotating it would orphan
        // any worker still running with the old one, and those keep reporting
        // until the next deploy restarts them.
        $token = (string) data_get($meta, 'queue_insights.token', '') ?: (string) Str::random(48);
        $meta['queue_insights'] = ['enabled' => $enabled, 'token' => $token];

        $this->site->forceFill(['meta' => $meta])->save();

        // Without the endpoint and token in the app's env the package installs
        // and then registers no listeners at all — silently, by design. Writing
        // them here means the same deploy that installs it also switches it on.
        $this->writeAgentEnv($enabled, $token);

        $this->toastSuccess($enabled
            ? __('The queue agent installs on the next deploy.')
            : __('The queue agent will not be installed. It stays in place until the next deploy removes it.'));
    }

    /**
     * Put the agent's endpoint and token into the site's env, or take them out.
     *
     * Written to dply's copy; the next deploy or env push carries it to the box.
     * Removing the keys is how "disable" works — the package stays installed
     * until a deploy removes it, and an agent with no endpoint is inert.
     */
    private function writeAgentEnv(bool $enabled, string $token): void
    {
        $parser = app(DotEnvFileParser::class);
        $writer = app(DotEnvFileWriter::class);

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $variables = $existing['variables'];

        if ($enabled) {
            $variables['DPLY_QUEUE_INSIGHTS_ENDPOINT'] = route('sites.queue-events', ['site' => $this->site->id]);
            $variables['DPLY_QUEUE_INSIGHTS_TOKEN'] = $token;
        } else {
            unset($variables['DPLY_QUEUE_INSIGHTS_ENDPOINT'], $variables['DPLY_QUEUE_INSIGHTS_TOKEN']);
        }

        $this->site->forceFill([
            'env_file_content' => $writer->render($variables, $existing['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();
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
        $this->queue_workspace_tab = 'activity';

        if (! in_array($this->activity_view, ['waiting', 'delayed'], true)) {
            $this->activity_view = 'waiting';
        }

        CollectSiteQueueJobsJob::dispatch((string) $this->site->id, $queue, $this->activity_view);
    }

    /**
     * The cached read for the inspected queue, parsed into rows.
     *
     * @return array{jobs: list<array{job: QueueJobPayload, available_in: ?int}>, driver: ?string, truncated: bool, error: ?string, read_at: ?string}|null
     */
    public function inspectedJobs(): ?array
    {
        if ($this->inspect_queue === '') {
            return null;
        }

        $scope = $this->activity_view === 'delayed' ? 'delayed' : 'waiting';
        $cached = Cache::get(CollectSiteQueueJobsJob::cacheKey((string) $this->site->id, $this->inspect_queue, $scope));

        if (! is_array($cached)) {
            return null;
        }

        $now = strtotime((string) ($cached['read_at'] ?? 'now')) ?: null;
        $jobs = [];

        foreach ((array) ($cached['jobs'] ?? []) as $row) {
            // Rows carry their release time now, so one shape covers both
            // scopes: a waiting job simply has no `available_in`.
            $json = is_array($row) ? (string) ($row['payload'] ?? '') : (string) $row;
            $parsed = QueueJobPayload::fromJson($json, $now);

            if ($parsed === null) {
                continue;
            }

            $jobs[] = [
                'job' => $parsed,
                'available_in' => is_array($row) && is_numeric($row['available_in'] ?? null) ? (int) $row['available_in'] : null,
            ];
        }

        return [
            'jobs' => $jobs,
            'driver' => $cached['driver'] ?? null,
            'truncated' => (bool) ($cached['truncated'] ?? false),
            'error' => $cached['error'] ?? null,
            'read_at' => $cached['read_at'] ?? null,
        ];
    }

    /**
     * Ask the box for one job's full payload.
     *
     * The list deliberately does not carry it — see
     * {@see ReadSiteQueueJobPayloadJob} — so this is a fresh read of a single
     * job, gated on the permission to change this site.
     */
    public function revealPayload(string $uuid): void
    {
        $this->authorize('update', $this->site);

        if ($this->payload_uuid === $uuid) {
            $this->payload_uuid = '';

            return;
        }

        $this->payload_uuid = $uuid;

        ReadSiteQueueJobPayloadJob::dispatch(
            (string) $this->site->id,
            $this->inspect_queue,
            $uuid,
            (string) auth()->id(),
            $this->activity_view === 'delayed' ? 'delayed' : 'waiting',
        );
    }

    /**
     * @return array{payload: ?string, error: ?string}|null
     */
    public function revealedPayload(): ?array
    {
        if ($this->payload_uuid === '') {
            return null;
        }

        return ReadSiteQueueJobPayloadJob::cached((string) $this->site->id, (string) auth()->id(), $this->payload_uuid);
    }

    /**
     * Switch the Activity panel between waiting, delayed, failed and history.
     */
    public function showActivity(string $view): void
    {
        $this->queue_workspace_tab = 'activity';
        $this->activity_view = in_array($view, ['waiting', 'delayed', 'failed', 'history'], true) ? $view : 'waiting';

        // Each view pays its own way: the reads happen when their view is
        // opened, so landing on Activity does not fire SSH reads for panels
        // nobody looked at.
        if ($this->activity_view === 'failed' && CollectSiteFailedJobsJob::cached((string) $this->site->id) === null) {
            $this->refreshFailedJobs();
        }

        // Delayed is read per queue, like waiting is, so it needs a queue chosen
        // before there is anything to fetch.
        if ($this->activity_view === 'delayed' && $this->inspect_queue !== '') {
            CollectSiteQueueJobsJob::dispatch((string) $this->site->id, $this->inspect_queue, 'delayed');
        }
    }

    public function refreshFailedJobs(): void
    {
        $this->authorize('view', $this->site);

        CollectSiteFailedJobsJob::dispatch((string) $this->site->id);
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, total: int, driver: ?string, error: ?string, read_at: ?string}|null
     */
    public function failedJobs(): ?array
    {
        return CollectSiteFailedJobsJob::cached((string) $this->site->id);
    }

    /** Put one failed job back on its queue. */
    public function retryFailed(string $uuid): void
    {
        $this->runFailedCommand('queue:retry', $uuid, __('Retrying the job — the list refreshes shortly.'));
    }

    /** Delete one failed job without running it again. */
    public function forgetFailed(string $uuid): void
    {
        $this->runFailedCommand('queue:forget', $uuid, __('Deleting the record — the list refreshes shortly.'));
    }

    /** Open the confirm modal for a bulk action ('retry_all' or 'flush'). */
    public function confirmFailedBulk(string $action): void
    {
        $this->authorize('update', $this->site);

        if (! in_array($action, ['retry_all', 'flush'], true)) {
            return;
        }

        $this->failed_action = $action;
        $this->dispatch('open-modal', 'queue-failed-confirm');
    }

    /**
     * Run the confirmed bulk action.
     *
     * Retrying everything can put thousands of jobs back on a queue at once and
     * flushing destroys the only record of what broke — neither belongs behind a
     * bare button, which is why both come through the modal.
     */
    public function runFailedBulk(): void
    {
        $action = $this->failed_action;
        $this->failed_action = '';
        $this->dispatch('close-modal', 'queue-failed-confirm');

        match ($action) {
            'retry_all' => $this->runFailedCommand('queue:retry', 'all', __('Retrying every failed job.')),
            'flush' => $this->runFailedCommand('queue:flush', null, __('Deleting every failed job.')),
            default => null,
        };
    }

    /**
     * Hand a failed-job command to the app over SSH.
     *
     * {@see ControlWorkerDaemonJob} already runs these for worker pools, with the
     * argument allowlisted to a uuid or "all" — a job identifier from a browser
     * ends up in a shell line, so that allowlist is the point.
     */
    private function runFailedCommand(string $command, ?string $arg, string $message): void
    {
        $this->authorize('update', $this->site);

        ControlWorkerDaemonJob::dispatch(
            (string) $this->site->id,
            $command,
            (string) auth()->id() ?: null,
            $arg,
        );

        // Re-read after the command has had time to land. Without it the list
        // still shows a job that was just retried, which reads as a no-op.
        CollectSiteFailedJobsJob::dispatch((string) $this->site->id)->delay(now()->addSeconds(10));

        $this->toastSuccess($message);
    }

    /**
     * Show the catalogue, scanning the release the first time.
     *
     * Scanning on tab-open rather than on page load: it is SSH work, and most
     * visits to this page are about depth and workers, not about what the app
     * could run.
     */
    public function showJobCatalog(): void
    {
        $this->queue_workspace_tab = 'catalog';

        if (CollectSiteJobClassesJob::cached((string) $this->site->id) === null) {
            $this->refreshJobCatalog();
        }
    }

    public function refreshJobCatalog(): void
    {
        $this->authorize('view', $this->site);

        CollectSiteJobClassesJob::dispatch((string) $this->site->id);
    }

    /**
     * The cached catalogue, filtered.
     *
     * @return array{jobs: list<array<string, mixed>>, truncated: bool, error: ?string, read_at: ?string}|null
     */
    public function jobCatalog(): ?array
    {
        $cached = CollectSiteJobClassesJob::cached((string) $this->site->id);

        if ($cached === null) {
            return null;
        }

        $filter = trim(mb_strtolower($this->catalog_filter));

        if ($filter !== '') {
            $cached['jobs'] = array_values(array_filter(
                $cached['jobs'],
                static fn (array $job): bool => str_contains(mb_strtolower((string) $job['class']), $filter),
            ));
        }

        return $cached;
    }

    /**
     * Run one of the app's own jobs, for real.
     *
     * The class is checked against the catalogue rather than trusted from the
     * request: this is a class name from the browser that ends up as
     * `newInstanceArgs` on the customer's box, and the catalogue is the only
     * list of names that were ever offered. {@see DispatchSiteTestJobJob}
     * re-checks it remotely too — the catalogue can be a deploy out of date.
     */
    public function dispatchTestJob(string $class): void
    {
        $this->authorize('update', $this->site);

        $catalog = CollectSiteJobClassesJob::cached((string) $this->site->id);
        $entry = collect((array) ($catalog['jobs'] ?? []))->firstWhere('class', $class);

        if ($entry === null) {
            $this->toastError(__('That job is not in this site\'s catalogue. Re-scan and try again.'));

            return;
        }

        if (in_array($entry['kind'] ?? 'job', ['mail', 'notification', 'broadcast'], true)) {
            $this->toastError(__('This is not dispatched on its own — it needs a recipient or an event.'));

            return;
        }

        $args = $this->parsedDispatchArgs($class);

        if ($args === null) {
            $this->addError('dispatch_args', __('Arguments must be a JSON array of numbers, strings or booleans — for example [12, "now"].'));

            return;
        }

        if (count($args) < (int) ($entry['required_args'] ?? 0)) {
            $this->addError('dispatch_args', __('This job needs :n argument(s).', ['n' => (int) $entry['required_args']]));

            return;
        }

        $run = $this->seedQueuedConsoleAction('queue_dispatch', __('Running :class', ['class' => class_basename($class)]));

        DispatchSiteTestJobJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            $class,
            (string) ($entry['queue'] ?? '') ?: $this->firstDrainedQueue(),
            $args,
        );

        $this->confirm_class = '';
        $this->dispatch_args = '';
        $this->dispatch('close-modal', 'queue-dispatch-confirm');

        $this->toastSuccess(__('Dispatched — progress shows in the console above.'));
    }

    /**
     * Ask before running someone's production job.
     *
     * A modal rather than `wire:confirm`: the browser's dialog renders as
     * "JavaScript from https://dply.io" above text the operator cannot read
     * beside the job it belongs to, and it cannot carry the argument field this
     * confirm needs anyway.
     */
    public function confirmDispatch(string $class): void
    {
        $this->authorize('update', $this->site);
        $this->resetValidation();

        $this->dispatch_args = '';
        $this->confirm_class = $class;

        $this->dispatch('open-modal', 'queue-dispatch-confirm');
    }

    /**
     * The catalogue row the confirm modal is describing.
     *
     * @return array<string, mixed>|null
     */
    public function confirmEntry(): ?array
    {
        if ($this->confirm_class === '') {
            return null;
        }

        $catalog = CollectSiteJobClassesJob::cached((string) $this->site->id);

        return collect((array) ($catalog['jobs'] ?? []))->firstWhere('class', $this->confirm_class);
    }

    /**
     * The typed arguments, or null when they are not a flat JSON array.
     *
     * Scalars only, and that is the whole boundary: an argument that arrives as
     * an array or object would be handed to `newInstanceArgs` on the customer's
     * box, and dply has no business shipping structures it cannot type-check.
     *
     * @return list<scalar|null>|null
     */
    private function parsedDispatchArgs(string $class): ?array
    {
        if ($this->confirm_class !== $class || trim($this->dispatch_args) === '') {
            return [];
        }

        $decoded = json_decode(trim($this->dispatch_args), true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        foreach ($decoded as $value) {
            if ($value !== null && ! is_scalar($value)) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * Load a worker into the form.
     *
     * The form fields are filled from the command itself rather than from
     * remembered state — Supervisor's copy is the truth, and a worker edited
     * over SSH since dply last looked must edit from what is actually running.
     */
    public function editWorker(string $id): void
    {
        $this->authorize('update', $this->site);
        $this->resetValidation();

        $program = $this->ownedProgram($id);

        if ($program === null) {
            return;
        }

        $command = QueueWorkerCommand::parse((string) $program->command);

        $this->edit_worker_id = (string) $program->id;
        $this->edit_command = (string) $program->command;
        $this->new_queue = implode(',', $command->queues()) ?: 'default';
        $this->new_connection = (string) ($command->connection ?? '');
        $this->new_processes = max(1, (int) $program->numprocs);
        $this->new_tries = (int) ($command->flags['tries'] ?? 3);
        $this->new_timeout = (int) ($command->flags['timeout'] ?? 60);
        $this->new_sleep = (int) ($command->flags['sleep'] ?? 3);
        $this->new_memory = (int) ($command->flags['memory'] ?? 128);
        $this->new_max_time = (int) ($command->flags['max-time'] ?? 0);
        $this->new_backoff = (string) ($command->flags['backoff'] ?? '');
        $this->new_max_jobs = (string) ($command->flags['max-jobs'] ?? '');
        $this->new_rest = (string) ($command->flags['rest'] ?? '');
        $this->new_stop_when_empty = in_array('stop-when-empty', $command->bools, true);

        $this->queue_workspace_tab = 'workers';
    }

    public function cancelEdit(): void
    {
        $this->edit_worker_id = '';
        $this->edit_command = '';
        $this->resetValidation();
    }

    /**
     * The command an edit would write, for the operator to read before saving.
     *
     * Rendered from the SAME path save uses, so the preview cannot drift from
     * what actually lands in the Supervisor conf.
     */
    public function editedCommand(): string
    {
        $program = $this->edit_worker_id !== '' ? $this->ownedProgram($this->edit_worker_id) : null;

        if ($program === null) {
            return '';
        }

        return $this->applyFormTo(QueueWorkerCommand::parse((string) $program->command))->render();
    }

    /** Apply the form's values to a parsed command, leaving unmodelled flags alone. */
    private function applyFormTo(QueueWorkerCommand $command): QueueWorkerCommand
    {
        return $command->with(
            [
                'queue' => trim($this->new_queue),
                'tries' => $this->new_tries,
                'timeout' => $this->new_timeout,
                'sleep' => $this->new_sleep,
                'memory' => $this->new_memory,
                'max-time' => $this->new_max_time > 0 ? $this->new_max_time : '',
                'backoff' => $this->new_backoff,
                'max-jobs' => $this->new_max_jobs,
                'rest' => $this->new_rest,
            ],
            ['stop-when-empty' => $this->new_stop_when_empty],
            trim($this->new_connection),
        );
    }

    /**
     * Save an edited worker and hand the new conf to Supervisor.
     *
     * `$edit_command` wins when it was changed by hand: the Advanced field is
     * an escape hatch for the flag dply does not model, and silently rebuilding
     * over it would make the escape hatch a lie.
     */
    public function saveWorker(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $program = $this->ownedProgram($this->edit_worker_id);

        if ($program === null) {
            return;
        }

        $this->validate([
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

        $rebuilt = $this->applyFormTo(QueueWorkerCommand::parse((string) $program->command))->render();
        $handEdited = trim($this->edit_command) !== '' && trim($this->edit_command) !== trim((string) $program->command);
        $command = $handEdited ? trim($this->edit_command) : $rebuilt;

        if (! QueueWorkerClassifier::isQueueWorker($command)) {
            // A command that stops being a queue worker vanishes from this page
            // and reappears under Daemons, which reads as dply losing it.
            $this->addError('edit_command', __('That command is not a queue worker any more. Manage it under Daemons instead.'));

            return;
        }

        $program->forceFill([
            'command' => $command,
            'numprocs' => $this->new_processes,
        ])->save();

        SupervisorDaemonAudit::log($this->server->fresh(), $program, 'edit_queue_worker', [
            'queue' => trim($this->new_queue),
            'hand_edited' => $handEdited,
        ]);

        $this->cancelEdit();

        try {
            // Rewrites the conf and restarts the program: queue:work finishes
            // its current job on TERM, so an edit costs one job's latency, not
            // the job itself.
            $provisioner->syncProgram($this->server->fresh(), (string) $program->id);
            $this->toastSuccess(__('Worker updated and restarted.'));
        } catch (\Throwable $e) {
            $this->toastError(__('Saved, but Supervisor did not pick it up: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));
        }
    }

    /**
     * Stop draining one queue without touching the worker's other work.
     *
     * A worker on `--queue=high,default` loses `default` and keeps serving
     * `high`; one that drains nothing else is stopped instead. The original
     * command is stashed on the site so resume restores exactly what was there,
     * including flags dply does not model.
     */
    public function pauseQueue(string $queue, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $paused = (array) data_get($this->site->meta, 'queue_paused', []);
        $touched = 0;

        foreach ($this->workers() as $program) {
            $command = QueueWorkerCommand::parse((string) $program->command);
            $queues = $command->queues();

            if (! in_array($queue, $queues, true)) {
                continue;
            }

            // The ORIGINAL line, stored whole. Rebuilding it on resume from the
            // remaining queue names would drop whatever flags dply does not
            // model — the same loss the edit form exists to prevent.
            $paused[$queue][(string) $program->id] = (string) $program->command;

            $remaining = array_values(array_filter($queues, static fn (string $q): bool => $q !== $queue));

            if ($remaining === []) {
                $program->forceFill(['is_active' => false])->save();
                $this->safely(fn () => $provisioner->stopProgramGroup($this->server->fresh(), (string) $program->id));
            } else {
                $program->forceFill(['command' => $command->withQueues($remaining)->render()])->save();
                $this->safely(fn () => $provisioner->syncProgram($this->server->fresh(), (string) $program->id));
            }

            SupervisorDaemonAudit::log($this->server->fresh(), $program, 'pause_queue', ['queue' => $queue]);
            $touched++;
        }

        if ($touched === 0) {
            $this->toastError(__('No worker on this site drains :q.', ['q' => $queue]));

            return;
        }

        $this->writeSiteMeta('queue_paused', $paused);
        $this->toastSuccess(__('Paused :q. Jobs keep arriving and wait for a worker.', ['q' => $queue]));
    }

    /** Put a paused queue back exactly as it was. */
    public function resumeQueue(string $queue, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $paused = (array) data_get($this->site->meta, 'queue_paused', []);
        $entries = (array) ($paused[$queue] ?? []);

        foreach ($entries as $programId => $original) {
            $program = SupervisorProgram::query()
                ->where('site_id', $this->site->id)
                ->find((string) $programId);

            // A worker deleted while paused simply has nothing to restore; the
            // stale entry is cleared below either way.
            if ($program === null || ! is_string($original) || $original === '') {
                continue;
            }

            $program->forceFill(['command' => $original, 'is_active' => true])->save();
            $this->safely(fn () => $provisioner->syncProgram($this->server->fresh(), (string) $program->id));
            SupervisorDaemonAudit::log($this->server->fresh(), $program, 'resume_queue', ['queue' => $queue]);
        }

        unset($paused[$queue]);
        $this->writeSiteMeta('queue_paused', $paused);

        $this->toastSuccess(__('Resumed :q.', ['q' => $queue]));
    }

    /**
     * Which queues are paused, and therefore resumable.
     *
     * @return list<string>
     */
    public function pausedQueues(): array
    {
        return array_keys((array) data_get($this->site->meta, 'queue_paused', []));
    }

    /** @param  array<string, mixed>  $value */
    private function writeSiteMeta(string $key, array $value): void
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        if ($value === []) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }

        $this->site->forceFill(['meta' => $meta])->save();
    }

    /**
     * Expand one history row.
     *
     * Everything shown is already on the record — connection, attempts, job id,
     * the exception — so this is a disclosure, not a fetch. The list stays
     * scannable and the detail is one click away instead of absent.
     */
    public function toggleHistoryRow(string $id): void
    {
        $this->history_open = $this->history_open === $id ? '' : $id;
    }

    /**
     * Open the alert rules — the site's defaults, or one queue's override.
     *
     * Loaded through the same resolver the evaluator uses, so the form shows
     * what would actually fire rather than what is stored: a queue with no
     * override displays the defaults it inherits.
     */
    public function editAlerts(string $queue = ''): void
    {
        $this->authorize('update', $this->site);
        $this->resetValidation();

        $this->alert_queue = $queue;

        $rules = $queue === ''
            ? SiteQueueAlertRules::fromArray(
                (array) data_get($this->site->meta, 'queue_alerts.defaults', []),
                (bool) data_get($this->site->meta, 'queue_alerts.enabled', true),
            )
            : SiteQueueAlertRules::for($this->site, $queue);

        $this->alerts_enabled = $rules->enabled;
        $this->alert_pending_over = $rules->pendingOver === null ? '' : (string) $rules->pendingOver;
        $this->alert_sustained_minutes = $rules->sustainedMinutes;
        $this->alert_oldest_over_s = $rules->oldestOverSeconds === null ? '' : (string) $rules->oldestOverSeconds;
        $this->alert_no_worker = $rules->noWorker;

        $this->dispatch('open-modal', 'queue-alerts');
    }

    public function saveAlerts(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'alert_pending_over' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'alert_oldest_over_s' => ['nullable', 'integer', 'min:1', 'max:604800'],
            'alert_sustained_minutes' => ['required', 'integer', 'min:'.SiteQueueAlertRules::MIN_SUSTAINED_MINUTES, 'max:1440'],
        ]);

        $stored = (array) data_get($this->site->meta, 'queue_alerts', []);
        $values = [
            'pending_over' => $this->alert_pending_over === '' ? null : (int) $this->alert_pending_over,
            'sustained_minutes' => $this->alert_sustained_minutes,
            'oldest_over_s' => $this->alert_oldest_over_s === '' ? null : (int) $this->alert_oldest_over_s,
            'no_worker' => $this->alert_no_worker,
        ];

        if ($this->alert_queue === '') {
            $stored['enabled'] = $this->alerts_enabled;
            $stored['defaults'] = $values;
        } else {
            $stored['queues'][$this->alert_queue] = $values;
        }

        // Changing a threshold clears what already fired: leaving the old marker
        // would keep a queue silent under a rule that no longer describes it.
        unset($stored['state']);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['queue_alerts'] = $stored;
        $this->site->forceFill(['meta' => $meta])->save();

        $this->dispatch('close-modal', 'queue-alerts');
        $this->toastSuccess($this->alert_queue === ''
            ? __('Alert rules saved for this site.')
            : __('Alert rules saved for :q.', ['q' => $this->alert_queue]));
    }

    /** Drop a queue's override so it follows the site defaults again. */
    public function clearAlertOverride(): void
    {
        $this->authorize('update', $this->site);

        if ($this->alert_queue === '') {
            return;
        }

        $stored = (array) data_get($this->site->meta, 'queue_alerts', []);
        unset($stored['queues'][$this->alert_queue], $stored['state']);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['queue_alerts'] = $stored;
        $this->site->forceFill(['meta' => $meta])->save();

        $this->dispatch('close-modal', 'queue-alerts');
        $this->toastSuccess(__(':q follows the site defaults again.', ['q' => $this->alert_queue]));
    }

    /** Queues carrying their own rules, so the list can say which are special. */
    public function overriddenAlertQueues(): array
    {
        return array_keys((array) data_get($this->site->meta, 'queue_alerts.queues', []));
    }

    /** Open the purge modal for one queue. */
    public function confirmPurge(string $queue): void
    {
        $this->authorize('update', $this->site);
        $this->resetValidation();

        $this->purge_queue = $queue;
        $this->purge_confirm = '';
        $this->dispatch('open-modal', 'queue-purge-confirm');
    }

    /**
     * Destroy every job on a queue.
     *
     * `queue:clear` takes waiting, delayed AND reserved jobs, and nothing comes
     * back — which is why it costs typing the queue's name and lands in the
     * audit log with the operator's id attached.
     */
    public function purgeQueue(): void
    {
        $this->authorize('update', $this->site);

        if (trim($this->purge_confirm) !== trim($this->purge_queue) || $this->purge_queue === '') {
            $this->addError('purge_confirm', __('Type the queue name exactly to confirm.'));

            return;
        }

        $queue = $this->purge_queue;

        ControlWorkerDaemonJob::dispatch(
            (string) $this->site->id,
            'queue:clear',
            (string) auth()->id() ?: null,
            $queue,
        );

        SupervisorDaemonAudit::log($this->server->fresh(), null, 'purge_queue', ['queue' => $queue]);

        $this->purge_queue = '';
        $this->purge_confirm = '';
        $this->dispatch('close-modal', 'queue-purge-confirm');

        CollectServerQueueSnapshotsJob::dispatch((string) $this->server->id)->delay(now()->addSeconds(15));

        $this->toastSuccess(__('Clearing :q — the depth reading updates shortly.', ['q' => $queue]));
    }

    /** Supervisor calls fail on unreachable boxes; a pause must still record its intent. */
    private function safely(callable $run): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            $this->toastError(__('Supervisor did not respond: :msg', ['msg' => Str::limit($e->getMessage(), 200)]));
        }
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
            // Jobs that actually RAN. Only the in-app agent can supply these:
            // a processed job leaves nothing behind in the store.
            'jobRuns' => SiteQueueJobRun::query()
                ->where('site_id', $this->site->id)
                ->orderByDesc('ran_at')
                ->limit(100)
                ->get(),
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
