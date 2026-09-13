<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\WorkerPools\WorkerDaemonBackend;
use App\Support\Sites\QueueWorkerPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Make a site able to process queued jobs, in one action.
 *
 * The steps exist separately today — an env editor, a worker form, a flag on
 * the Pipeline page — and getting them all right is four surfaces and some
 * knowledge nobody should need. Worse, the ordering matters: an env written but
 * not pushed does nothing, and an env pushed while `config:cache` is warm does
 * nothing either, which is the same trap that took outbidpixels down this
 * morning with a stale route cache.
 *
 * Stops at the first failure and keeps what already succeeded. A written env
 * and a created worker are each useful on their own; unwinding a correct env
 * because a later step timed out would destroy work the operator wants. The
 * emitter names the step that broke so a retry can resume there.
 */
class SetUpSiteQueueingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        /** Driver to configure, already chosen by the caller from what the site has. */
        public string $driver,
        public ?string $userId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(
        ExecuteRemoteTaskOnServer $exec,
        SupervisorProvisioner $provisioner,
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
    ): void {
        $emit = new ConsoleEmitter($this->consoleActionId);

        // Leave `queued` immediately. A row still queued after 45s is what the
        // banner calls "queue worker did not pick this up" — so without this the
        // work below is judged by a clock that only measures pickup, and a setup
        // whose SSH steps take longer than that false-alarms while still running.
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => DB::raw('coalesce(started_at, now())'),
            'updated_at' => now(),
        ]);

        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null) {
            $this->fail($emit, __('This site has no server to set queueing up on.'));

            return;
        }

        // 1 — env ------------------------------------------------------------
        $emit->step('setup', __('Setting QUEUE_CONNECTION to :d …', ['d' => $this->driver]));

        $existing = $parser->parse((string) ($site->env_file_content ?? ''));
        $variables = $existing['variables'];

        if (($variables['QUEUE_CONNECTION'] ?? null) === $this->driver) {
            $emit->step('setup', __('QUEUE_CONNECTION was already :d.', ['d' => $this->driver]));
        } else {
            $variables['QUEUE_CONNECTION'] = $this->driver;
            $site->forceFill([
                'env_file_content' => $writer->render($variables, $existing['comments']),
                'env_cache_origin' => 'local-edit',
            ])->save();
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

        // `dply` only resolves once the queue package is in the release. Pushing
        // it before then points every dispatch at a connection the app cannot
        // load; the next deploy installs the package and ships this same env.
        if ($this->driver === 'dply') {
            try {
                $packageInstalled = $this->queuePackageInstalled($exec, $site, $dir);
            } catch (\Throwable $e) {
                // Not "saved": a check that never ran must not read as a switch
                // that was deliberately deferred.
                $this->fail($emit, __('Could not check for the dply queue package: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

                return;
            }

            if (! $packageInstalled) {
                // The deploy that installs the package runs this job again.
                $this->markSwitchPending($site, $this->driver);
                $this->succeed($emit, __('Saved. It takes effect on your next deploy, which installs the dply queue package — the workers switch then.'));

                return;
            }
        }

        // A switch somewhere else supersedes a dply switch still waiting on its
        // deploy. The same switch stays pending until it succeeds, so a failed
        // step below is retried by the next deploy rather than forgotten.
        if (data_get($site->meta, 'queue_switch_pending') !== $this->driver) {
            $this->markSwitchPending($site, null);
        }

        // 2 — push it to the box ---------------------------------------------
        // Inline, not dispatched: the next steps are only correct once the file
        // is actually on disk, and a queued push would race them.
        $emit->step('setup', __('Pushing the .env to :server …', ['server' => (string) $site->server->name]));

        try {
            // handle() takes an injected SiteEnvPusher, so a bare method call
            // throws ArgumentCountError — which the catch below turned into a
            // "could not push the .env" every single time. Resolve through the
            // container instead.
            app()->call([app(PushSiteEnvJob::class, ['siteId' => $this->siteId, 'userId' => $this->userId]), 'handle']);
        } catch (\Throwable $e) {
            $this->fail($emit, __('Could not push the .env: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

            return;
        }

        // 3 — clear the config cache -----------------------------------------
        // Mandatory, not tidy-up. With a cached config the app keeps the OLD
        // QUEUE_CONNECTION and every later step passes while jobs still run
        // inline — a success that is indistinguishable from the bug.
        $emit->step('setup', __('Clearing the config cache so the app sees it …'));

        try {
            $exec->runInlineBash(
                $site->server,
                'site:queue-setup-config-clear',
                sprintf('cd %s && php artisan config:clear 2>&1 || true', escapeshellarg($dir)),
                timeoutSeconds: 60,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            $this->fail($emit, __('Could not clear the config cache: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

            return;
        }

        // 4 — workers that read the new connection ----------------------------
        // Starts before stops, so there is no moment with nothing draining.
        // Stopped programs are deactivated, not deleted: switching back brings
        // them back. Idempotent — a worker that already drains this connection
        // is left alone rather than stacked, which would double concurrency.
        $backend = app(WorkerDaemonBackend::class);

        // Units first: a queue worker under systemd is invisible to this page
        // and to deploy restarts. ensure() starts each program before its unit
        // comes down, and leaves the units alone if Supervisor fails.
        $moving = QueueWorkerPlan::for($site, $this->driver)->move;
        if ($moving->isNotEmpty()) {
            $emit->step('setup', trans_choice('Moving :count systemd unit to Supervisor first …|Moving :count systemd units to Supervisor first …', $moving->count(), ['count' => $moving->count()]));
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['worker_process_manager'] = WorkerPool::PM_SUPERVISOR;
            $site->forceFill(['meta' => $meta])->save();

            try {
                $backend->ensure($site->fresh());
            } catch (\Throwable $e) {
                $this->fail($emit, __('Could not move the workers to Supervisor: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

                return;
            }

            $site->refresh();
        }

        $plan = QueueWorkerPlan::for($site, $this->driver);

        if (! $plan->changesAnything()) {
            $emit->step('setup', __('The workers already read :d — leaving them alone.', ['d' => $this->driver]));
        }

        foreach ($plan->start as $program) {
            $emit->step('setup', __('Starting :p again …', ['p' => $program->slug]));
            $program->forceFill(['is_active' => true])->save();
            $source = $backend->sourceProcessFor($site, $program);
            $source?->forceFill(['is_active' => true])->save();
            $this->rememberQueueStop($site, $source, stopped: false);

            try {
                $provisioner->syncProgram($site->server->fresh(), (string) $program->id);
            } catch (\Throwable $e) {
                $this->fail($emit, __(':p did not start: :msg', ['p' => $program->slug, 'msg' => Str::limit($e->getMessage(), 300)]));

                return;
            }
        }

        if ($plan->createDefault) {
            $emit->step('setup', __('Creating a queue worker …'));

            $program = SupervisorProgram::query()->create([
                'server_id' => $site->server->id,
                'site_id' => $site->id,
                'slug' => Str::slug($site->name.'-queue-default'),
                'program_type' => 'queue',
                'command' => "php artisan queue:work --queue='default' --sleep=3 --timeout=60 --tries=3 --memory=128 --max-time=3600",
                'directory' => $dir,
                'user' => $site->effectiveSystemUser($site->server) ?: 'dply',
                'numprocs' => 1,
                'is_active' => true,
            ]);

            try {
                $provisioner->syncProgram($site->server->fresh(), (string) $program->id);
            } catch (\Throwable $e) {
                $this->fail($emit, __('Worker saved, but Supervisor did not pick it up: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

                return;
            }
        }

        if ($plan->stop->isNotEmpty()) {
            foreach ($plan->stop as $program) {
                $emit->step('setup', __('Stopping :p — it cannot read :d. Kept, so switching back restarts it.', ['p' => $program->slug, 'd' => $this->driver]));
                $program->forceFill(['is_active' => false])->save();
                $source = $backend->sourceProcessFor($site, $program);
                $source?->forceFill(['is_active' => false])->save();
                $this->rememberQueueStop($site, $source, stopped: true);
            }

            try {
                // sync() only writes active programs, so the stopped confs go.
                $provisioner->sync($site->server->fresh());
            } catch (\Throwable $e) {
                $this->fail($emit, __('Could not stop the old workers: :msg', ['msg' => Str::limit($e->getMessage(), 300)]));

                return;
            }
        }

        // 5 — make deploys reach it -------------------------------------------
        if (! ($site->restart_supervisor_programs_after_deploy ?? false)) {
            $site->forceFill(['restart_supervisor_programs_after_deploy' => true])->save();
            $emit->step('setup', __('Deploys will now restart the workers.'));
        }

        $this->markSwitchPending($site, null);
        $this->succeed($emit, __('Queueing is set up. Dispatch a job and it will be picked up.'));
    }

    /**
     * A throw the steps did not catch — or the 300s timeout — still has to close
     * the row. Otherwise the run sits in `running` until the reaper marks it
     * failed, and the operator reads a banner about worker pickup for a job the
     * worker demonstrably picked up.
     */
    public function failed(\Throwable $e): void
    {
        $this->complete(failed: true, error: Str::limit($e->getMessage(), 500));
    }

    /**
     * Terminal states are written to the row, not just emitted: the banner and
     * the Livewire outcome watcher both read `console_actions.status`, and a row
     * left `queued` reports "no queue worker picked this up" no matter what the
     * console output says.
     */
    /**
     * A deploy re-syncs manifest processes as active. This list is what keeps
     * a worker the switch stopped from coming back on the next one — the
     * process row's own meta is rewritten from the manifest, so it cannot hold it.
     */
    private function rememberQueueStop(Site $site, ?SiteProcess $process, bool $stopped): void
    {
        if ($process === null) {
            return;
        }

        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $names = array_values(array_diff((array) ($meta['queue_stopped_processes'] ?? []), [$process->name]));

        if ($stopped) {
            $names[] = $process->name;
        }

        $meta['queue_stopped_processes'] = $names;
        $site->forceFill(['meta' => $meta])->save();
    }

    private function markSwitchPending(Site $site, ?string $driver): void
    {
        // Earlier steps write meta through other paths; never save stale meta back.
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        if (($meta['queue_switch_pending'] ?? null) === $driver) {
            return;
        }

        if ($driver === null) {
            unset($meta['queue_switch_pending']);
        } else {
            $meta['queue_switch_pending'] = $driver;
        }
        $site->forceFill(['meta' => $meta])->save();
    }

    private function queuePackageInstalled(ExecuteRemoteTaskOnServer $exec, Site $site, string $dir): bool
    {
        $package = (string) config('dply.queue_insights.package', 'dply/queue-insights');

        $out = $exec->runInlineBash(
            $site->server,
            'site:queue-setup-package-check',
            sprintf('test -d %s && echo DPLY_PKG_YES || echo DPLY_PKG_NO', escapeshellarg($dir.'/vendor/'.$package)),
            timeoutSeconds: 30,
            asRoot: false,
        );

        return str_contains((string) $out->buffer, 'DPLY_PKG_YES');
    }

    private function fail(ConsoleEmitter $emit, string $message): void
    {
        $emit->error($message, 'setup');
        $this->complete(failed: true, error: Str::limit($message, 500));
    }

    private function succeed(ConsoleEmitter $emit, string $message): void
    {
        $emit->success($message, 'setup');
        $this->complete(failed: false);
    }

    private function complete(bool $failed, ?string $error = null): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
