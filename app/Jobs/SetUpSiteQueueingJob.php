<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Support\Sites\QueueWorkerClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null) {
            $emit->error(__('This site has no server to set queueing up on.'), 'setup');

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

        // 2 — push it to the box ---------------------------------------------
        // Inline, not dispatched: the next steps are only correct once the file
        // is actually on disk, and a queued push would race them.
        $emit->step('setup', __('Pushing the .env to :server …', ['server' => (string) $site->server->name]));

        try {
            app(PushSiteEnvJob::class, ['siteId' => $this->siteId, 'userId' => $this->userId])->handle();
        } catch (\Throwable $e) {
            $emit->error(__('Could not push the .env: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'setup');

            return;
        }

        // 3 — clear the config cache -----------------------------------------
        // Mandatory, not tidy-up. With a cached config the app keeps the OLD
        // QUEUE_CONNECTION and every later step passes while jobs still run
        // inline — a success that is indistinguishable from the bug.
        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');
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
            $emit->error(__('Could not clear the config cache: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'setup');

            return;
        }

        // 4 — a worker to drain it -------------------------------------------
        $existingWorker = SupervisorProgram::query()
            ->where('site_id', $site->id)
            ->get()
            ->first(fn (SupervisorProgram $p): bool => QueueWorkerClassifier::isQueueWorker($p->command));

        if ($existingWorker !== null) {
            // Idempotent: re-running must not stack a second worker on the same
            // queue, which would double concurrency silently.
            $emit->step('setup', __('A queue worker already exists — leaving it alone.'));
        } else {
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
                $emit->error(__('Worker saved, but Supervisor did not pick it up: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'setup');

                return;
            }
        }

        // 5 — make deploys reach it -------------------------------------------
        if (! ($site->restart_supervisor_programs_after_deploy ?? false)) {
            $site->forceFill(['restart_supervisor_programs_after_deploy' => true])->save();
            $emit->step('setup', __('Deploys will now restart the workers.'));
        }

        $emit->success(__('Queueing is set up. Dispatch a job and it will be picked up.'), 'setup');
    }
}
