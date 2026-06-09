<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ensures the site's app server has the PHP client extension for a database
 * engine it's been bound to — e.g. attaching a Postgres database to an app
 * server that was provisioned with MySQL leaves PHP unable to speak pgsql
 * ("could not find driver"). Installs `php{ver}-{driver}` and restarts FPM.
 *
 * Idempotent (apt install is a no-op when present) and best-effort — never
 * fails the attach. The remote DB engine, not the app server's own DB, decides
 * which driver is needed, so this runs on the SITE's server.
 */
class EnsureSitePhpDatabaseDriverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $siteId, public string $engine)
    {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if (! $site instanceof Site || ! $site->server instanceof Server) {
            return;
        }
        $server = $site->server;

        $driver = $this->driverFor($this->engine);
        if ($driver === null) {
            return;
        }

        $php = trim((string) ($server->meta['php_version'] ?? ''));
        if ($php === '' || $php === 'none') {
            return;
        }

        $pkg = 'php'.$php.'-'.$driver;
        $fpm = 'php'.$php.'-fpm';

        $script = <<<BASH
# already loaded? then nothing to do (covers both CLI + FPM SAPIs)
if php -m 2>/dev/null | grep -qi '^{$driver}\$'; then echo "[dply] php {$driver} already present"; exit 0; fi
DEBIAN_FRONTEND=noninteractive apt-get install -y {$pkg} 2>&1 | tail -2 || echo "[dply] apt install {$pkg} failed (non-fatal)"
systemctl restart {$fpm} 2>&1 | tail -1 || true
php -m 2>/dev/null | grep -qi '^{$driver}\$' && echo "[dply] php {$driver} installed" || echo "[dply] php {$driver} still missing after install"
BASH;

        try {
            $exec->runInlineBash($server, 'site:php-db-driver:'.$driver, $script, timeoutSeconds: 150, asRoot: true);
        } catch (\Throwable $e) {
            Log::warning('EnsureSitePhpDatabaseDriverJob failed', [
                'site_id' => $site->id,
                'engine' => $this->engine,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map a database engine to its PHP PDO driver extension package suffix.
     */
    private function driverFor(string $engine): ?string
    {
        $e = strtolower(trim($engine));

        return match (true) {
            str_contains($e, 'postgres'), str_contains($e, 'pgsql') => 'pgsql',
            str_contains($e, 'mysql'), str_contains($e, 'maria') => 'mysql',
            default => null,
        };
    }
}
