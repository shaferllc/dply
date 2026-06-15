<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use App\Support\Sites\SiteFixers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees the PHP `redis` client extension is present on a site's server
 * after a Redis binding is attached.
 *
 * Attaching Redis points the app at phpredis (REDIS_CLIENT=phpredis) and, via
 * the one-click flow, can flip CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION to
 * `redis`. If the box's PHP has no redis extension the app boots straight into
 * a 500 (`Class "Redis" not found`). Provisioning installs `phpX-redis` only
 * best-effort (a briefly-unreachable PPA silently skips it), so connecting
 * Redis must not assume it's there — it checks and installs if missing, so the
 * binding "just works" instead of dead-ending on a runtime fatal.
 *
 * Idempotent: when the extension is already loaded (the common case on a
 * cleanly provisioned box) it no-ops in one cheap `php -m` round-trip and never
 * touches apt. The install body itself is the audited {@see SiteFixers}
 * `install_php_redis` command, so there is one source of truth for how the
 * extension gets built.
 */
class EnsureSitePhpRedisExtensionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
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

        try {
            $emit->step('redis-ext', 'Checking the PHP Redis extension on '.(string) $site->server->name.' …');

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(12)) {
                throw new \RuntimeException('Could not open SSH to '.(string) $site->server->name.'.');
            }

            // One round-trip that (1) short-circuits if there's no server PHP,
            // (2) short-circuits if redis is already loaded, else (3) installs
            // it and re-checks. Markers (not exit codes) drive the verdict —
            // SshConnection::exec() never throws on non-zero.
            $install = (string) SiteFixers::spec('install_php_redis')['command'];
            $script = implode("\n", [
                'if ! command -v php >/dev/null 2>&1; then echo DPLY_NO_PHP; exit 0; fi',
                "if php -m 2>/dev/null | grep -qi '^redis$'; then echo DPLY_HAVE_REDIS; exit 0; fi",
                'echo DPLY_INSTALLING',
                $install,
                "php -m 2>/dev/null | grep -qi '^redis$' && echo DPLY_REDIS_OK || echo DPLY_REDIS_MISSING",
            ]);

            $out = $conn->exec('sudo -n bash -lc '.escapeshellarg($script), $this->timeout - 30);

            if (str_contains($out, 'DPLY_NO_PHP')) {
                $emit->success('No server-side PHP on this host — nothing to install.', 'redis-ext');
                $this->complete(failed: false);

                return;
            }

            if (str_contains($out, 'DPLY_HAVE_REDIS')) {
                $emit->success('The PHP Redis extension is already installed.', 'redis-ext');
                $this->complete(failed: false);

                return;
            }

            $clean = trim((string) preg_replace('/DPLY_(INSTALLING|REDIS_OK|REDIS_MISSING)/', '', $out));
            if ($clean !== '') {
                $emit->step('redis-ext', $clean);
            }

            if (str_contains($out, 'DPLY_REDIS_OK')) {
                $emit->success('Installed the PHP Redis extension and reloaded PHP-FPM.', 'redis-ext');
                $this->complete(failed: false);

                return;
            }

            $emit->error('Could not install the PHP Redis extension — the app will fail with Class "Redis" not found until it is present.', 'redis-ext');
            $this->complete(failed: true, error: 'PHP Redis extension install did not complete.');
        } catch (\Throwable $e) {
            $emit->error('Ensuring the PHP Redis extension failed: '.mb_substr($e->getMessage(), 0, 300), 'redis-ext');
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
