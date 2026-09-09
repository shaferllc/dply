<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Make a site broadcast over its own Reverb server, in one action.
 *
 * The broadcasting binding has already minted the credentials, allocated the
 * loopback port and written `meta.laravel_reverb` (which is what makes the
 * webserver builders emit the proxy). None of that runs anything, so this job
 * does the four remote steps that turn it into a working websocket server:
 * require the package, get the env onto the box, run the daemon, reload the
 * vhost.
 *
 * Ordering is the whole point — a supervisor program started before the .env
 * is on disk boots Reverb with default credentials the app does not share, and
 * the failure looks like "connected, but no events arrive" rather than an
 * error. Stops at the first failure and keeps what already succeeded; the
 * emitter names the step that broke so a retry can resume there.
 */
class SetUpSiteReverbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** composer require on a cold cache is the long pole here. */
    public int $timeout = 900;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public ?string $userId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec, SupervisorProvisioner $provisioner): void
    {
        $emit = new ConsoleEmitter($this->consoleActionId);
        $site = Site::query()->with(['server', 'bindings'])->find($this->siteId);

        if ($site === null || $site->server === null) {
            $emit->error(__('This site has no server to run Reverb on.'), 'reverb');

            return;
        }

        $binding = $site->bindings->firstWhere('type', 'broadcasting');
        $env = is_array($binding?->injected_env) ? $binding->injected_env : [];
        $port = (int) ($env['REVERB_SERVER_PORT'] ?? 0);

        if ($port <= 0) {
            $emit->error(__('The broadcasting resource has no Reverb port — reconnect it.'), 'reverb');

            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');
        if ($dir === '') {
            $emit->error(__('This site has no deployed app directory yet — deploy it once, then connect Reverb.'), 'reverb');

            return;
        }

        // 1 — the package ------------------------------------------------------
        // dply cannot edit the app's composer.json, so the dependency goes on
        // the box directly and the next deploy's composer install picks it up.
        // Same trade-off (and same shape) as the Lookout/mail-transport path in
        // {@see EnsureSiteComposerPackageJob}; inlined here because that job
        // owns a console action of its own and this flow needs one console and
        // strict ordering with the steps below.
        $emit->step('reverb', __('Making sure laravel/reverb is required …'));

        $dirEsc = escapeshellarg($dir);
        $script = implode("\n", [
            "if ! command -v composer >/dev/null 2>&1; then echo DPLY_NO_COMPOSER; exit 0; fi",
            "if composer --working-dir={$dirEsc} show laravel/reverb >/dev/null 2>&1; then echo DPLY_HAVE; exit 0; fi",
            "composer --working-dir={$dirEsc} require laravel/reverb --no-interaction --no-scripts --no-audit 2>&1",
            "composer --working-dir={$dirEsc} show laravel/reverb >/dev/null 2>&1 && echo DPLY_OK || echo DPLY_FAILED",
        ]);

        try {
            $out = $exec->runInlineBash(
                $site->server,
                'site:reverb-composer-require',
                $script,
                timeoutSeconds: $this->timeout - 120,
                asRoot: false,
                // Markers, not the exit code, decide the verdict — the same
                // reason EnsureSiteComposerPackageJob reads them.
            )->getBuffer();
        } catch (\Throwable $e) {
            $emit->error(__('Could not install laravel/reverb: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'reverb');

            return;
        }

        if (str_contains($out, 'DPLY_NO_COMPOSER')) {
            $emit->error(__('Composer is not installed on this server — require laravel/reverb in the app manually.'), 'reverb');

            return;
        }

        if (str_contains($out, 'DPLY_FAILED')) {
            $emit->step('reverb', Str::limit(trim($out), 4000));
            $emit->error(__('composer require laravel/reverb did not complete — see the output.'), 'reverb');

            return;
        }

        $emit->step('reverb', str_contains($out, 'DPLY_HAVE')
            ? __('laravel/reverb was already required.')
            : __('laravel/reverb added — it ships on the next deploy too.'));

        // 2 — the env onto the box --------------------------------------------
        // Inline, not dispatched: step 4 boots a daemon that reads this file,
        // and a queued push would race it. The pusher merges the binding's
        // connection variables in, so REVERB_* land without being written into
        // the site's own .env content.
        $emit->step('reverb', __('Pushing the .env to :server …', ['server' => (string) $site->server->name]));

        try {
            // ->handle() takes an injected dependency, so it goes through the
            // container's call() rather than a bare method call.
            app()->call([app(PushSiteEnvJob::class, ['siteId' => $this->siteId, 'userId' => $this->userId]), 'handle']);
        } catch (\Throwable $e) {
            $emit->error(__('Could not push the .env: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'reverb');

            return;
        }

        // 3 — clear the config cache ------------------------------------------
        // Mandatory, not tidy-up: with a warm config cache the app keeps the old
        // BROADCAST_CONNECTION and every later step passes while events still go
        // nowhere — a success indistinguishable from the bug.
        $emit->step('reverb', __('Clearing the config cache so the app sees it …'));

        try {
            $exec->runInlineBash(
                $site->server,
                'site:reverb-config-clear',
                sprintf('cd %s && php artisan config:clear 2>&1 || true', $dirEsc),
                timeoutSeconds: 60,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            $emit->error(__('Could not clear the config cache: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'reverb');

            return;
        }

        // 4 — the daemon -------------------------------------------------------
        $existing = SupervisorProgram::query()
            ->where('site_id', $site->id)
            ->where('program_type', 'reverb')
            ->first();

        // --host/--port are passed explicitly rather than left to REVERB_SERVER_*
        // so the bind does not depend on the env file the app also reads. 127.0.0.1
        // keeps the raw port off the public interface; the vhost is the only door.
        $command = sprintf('php artisan reverb:start --host=127.0.0.1 --port=%d', $port);

        if ($existing !== null) {
            $emit->step('reverb', __('Updating the Reverb daemon …'));
            $existing->forceFill(['command' => $command, 'directory' => $dir, 'is_active' => true])->save();
            $program = $existing;
        } else {
            $emit->step('reverb', __('Creating the Reverb daemon …'));
            $program = SupervisorProgram::query()->create([
                'server_id' => $site->server->id,
                'site_id' => $site->id,
                'slug' => Str::slug($site->name.'-reverb'),
                'program_type' => 'reverb',
                'command' => $command,
                'directory' => $dir,
                'user' => $site->effectiveSystemUser($site->server) ?: 'dply',
                'numprocs' => 1,
                'is_active' => true,
            ]);
        }

        try {
            $provisioner->syncProgram($site->server->fresh(), (string) $program->id);
        } catch (\Throwable $e) {
            $emit->error(__('Reverb saved, but Supervisor did not pick it up: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'reverb');

            return;
        }

        // 5 — the vhost --------------------------------------------------------
        // meta.laravel_reverb is already written, so rebuilding the config is
        // what actually publishes wss:// and the Pusher HTTP API for the app.
        // Without this the daemon runs and nothing can reach it.
        $emit->step('reverb', __('Publishing the websocket route on the webserver …'));

        try {
            app()->call([app(ApplySiteWebserverConfigJob::class, ['siteId' => $this->siteId, 'userId' => $this->userId]), 'handle']);
        } catch (\Throwable $e) {
            $emit->error(__('Reverb is running, but the webserver config did not reload: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'reverb');

            return;
        }

        // Deploys must restart it too, or a release swap leaves Reverb running
        // the previous build's code.
        if (! ($site->restart_supervisor_programs_after_deploy ?? false)) {
            $site->forceFill(['restart_supervisor_programs_after_deploy' => true])->save();
            $emit->step('reverb', __('Deploys will now restart Reverb.'));
        }

        $emit->success(__('Reverb is live on :host. Echo connects with no further setup.', [
            'host' => (string) ($env['REVERB_HOST'] ?? $site->server->name),
        ]), 'reverb');
    }
}
