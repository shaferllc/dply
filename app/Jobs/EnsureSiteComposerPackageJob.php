<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a Composer package is required in a site's deployed app after a
 * binding that depends on it is attached.
 *
 * Some bindings inject env a client SDK reads, but the SDK only loads if the
 * app actually requires its package. dply can't edit the app's composer.json,
 * so for one-click resources like Lookout we add the dependency on the box
 * directly — `composer require` in the working tree — so the next deploy's
 * `composer install` already has it and the binding "just works" instead of the
 * injected env sitting inert.
 *
 * Idempotent: when the package is already present it no-ops after one cheap
 * `composer show` and never touches the lock file. Runs as the site's deploy
 * user (the working tree is owned by them), never root. Markers — not exit codes
 * — drive the verdict, since {@see \App\Services\Ssh\SshConnection::exec()}
 * never throws on non-zero.
 */
class EnsureSiteComposerPackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $package = 'lookout/tracing',
    ) {}

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($site === null || $action === null || $site->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $conn = null;

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');
        $user = trim((string) $site->server->ssh_user) ?: (string) config('server_provision.deploy_ssh_user', 'dply');

        try {
            $emit->step('composer-pkg', 'Ensuring '.$this->package.' is installed in '.$dir.' …');

            if ($dir === '') {
                $emit->success('No deployed app directory yet — '.$this->package.' will be added on the next deploy.', 'composer-pkg');
                $this->complete(failed: false);

                return;
            }

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(12)) {
                throw new \RuntimeException('Could not open SSH to '.(string) $site->server->name.'.');
            }

            $dirEsc = escapeshellarg($dir);
            $pkgEsc = escapeshellarg($this->package);
            $inner = implode("\n", [
                "if [ ! -d {$dirEsc} ]; then echo DPLY_NO_DIR; exit 0; fi",
                "if [ ! -f {$dirEsc}/composer.json ]; then echo DPLY_NO_COMPOSER_JSON; exit 0; fi",
                'if ! command -v composer >/dev/null 2>&1; then echo DPLY_NO_COMPOSER; exit 0; fi',
                "if composer --working-dir={$dirEsc} show {$pkgEsc} >/dev/null 2>&1; then echo DPLY_HAVE; exit 0; fi",
                'echo DPLY_INSTALLING',
                "composer --working-dir={$dirEsc} require {$pkgEsc} --no-interaction --no-scripts --no-audit 2>&1",
                "composer --working-dir={$dirEsc} show {$pkgEsc} >/dev/null 2>&1 && echo DPLY_OK || echo DPLY_FAILED",
            ]);
            $script = 'sudo -u '.escapeshellarg($user).' -H bash -lc '.escapeshellarg($inner);

            $out = $conn->exec($script, $this->timeout - 30);

            if (str_contains($out, 'DPLY_NO_DIR') || str_contains($out, 'DPLY_NO_COMPOSER_JSON')) {
                $emit->success('No composer.json on the box yet — '.$this->package.' will be added on the next deploy.', 'composer-pkg');
                $this->complete(failed: false);

                return;
            }

            if (str_contains($out, 'DPLY_NO_COMPOSER')) {
                $emit->error('Composer is not installed on this server — add '.$this->package.' to the app manually.', 'composer-pkg');
                $this->complete(failed: true, error: 'Composer not found on server.');

                return;
            }

            if (str_contains($out, 'DPLY_HAVE')) {
                $emit->success($this->package.' is already required by the app.', 'composer-pkg');
                $this->complete(failed: false);

                return;
            }

            $clean = trim((string) preg_replace('/DPLY_(INSTALLING|OK|FAILED)/', '', $out));
            if ($clean !== '') {
                $emit->step('composer-pkg', mb_substr($clean, 0, 4000));
            }

            if (str_contains($out, 'DPLY_OK')) {
                $emit->success('Added '.$this->package.' — it ships on the next deploy.', 'composer-pkg');
                $this->complete(failed: false);

                return;
            }

            $emit->error('Could not add '.$this->package.' — require it in the app manually so the SDK loads.', 'composer-pkg');
            $this->complete(failed: true, error: 'composer require '.$this->package.' did not complete.');
        } catch (\Throwable $e) {
            $emit->error('Installing '.$this->package.' failed: '.mb_substr($e->getMessage(), 0, 300), 'composer-pkg');
            $this->complete(failed: true, error: mb_substr($e->getMessage(), 0, 500));
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
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
