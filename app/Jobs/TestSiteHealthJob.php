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
use Illuminate\Support\Facades\Http;

/**
 * End-to-end "will the site actually load" smoke test, behind the Environment
 * tab's "Test site" button. Per-variable checks (missing-required, the config
 * validator, per-resource connectivity) can all pass while the app still 500s
 * on boot, so this exercises the real thing AND inspects the server state that
 * env values can't reveal:
 *
 *   1. HTTP GET the site URL and read the status code.
 *   2. SSH to the server and check the classic "deployed but won't load" traps:
 *        - PHP is missing the DB driver the app uses (pdo_pgsql / pdo_mysql →
 *          "could not find driver").
 *        - The config cache is stale (cached before the current .env), so the
 *          app runs on old/empty values.
 *        - Front-end assets were never built (no Vite manifest).
 *      …plus, on an HTTP failure, the latest error from the app log.
 *
 * Results stream into the page-top console banner. The run is marked failed if
 * the site didn't load or a boot-breaking server issue (missing driver) is
 * found, so the banner goes red and the operator knows the deploy isn't usable.
 */
class TestSiteHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    /** @var array<string, array{key: string, label: string, reason: string}> */
    private array $remediations = [];

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
    ) {}

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        if ($site === null || $action === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);

        // 1) HTTP check.
        $httpFailed = false;
        $httpOk = $this->httpCheck($site, $emit, $httpFailed);

        // 2) Server-state checks (driver / config cache / assets) + log on failure.
        $serverHardFailure = $this->serverChecks($site, $factory, $emit, tailLog: $httpFailed);

        $failed = $httpFailed || $serverHardFailure;

        // Persist the detected fixes so the Environment tab can surface the
        // matching one-click buttons (Run migrations, Clear config cache, …).
        $this->persistRemediations($site, ok: ! $failed);
        if ($this->remediations !== []) {
            $labels = implode(', ', array_map(fn ($r) => $r['label'], $this->remediations));
            $emit->warn('Suggested fix: '.$labels.' — one-click buttons are on the Environment tab.', 'result');
        }

        if (! $failed && $httpOk) {
            $emit->success('result', 'The site loaded and no boot-breaking server issues were found.');
        } elseif (! $httpFailed && $serverHardFailure) {
            $emit->warn('The site responded, but a boot-breaking server issue was found — fix it before it bites.', 'result');
        }

        $this->complete(failed: $failed);
    }

    /**
     * Record an actionable smart-fix (deduped by key). Only keys known to
     * {@see SiteFixers} become buttons; the reason defaults to the fixer's.
     */
    private function suggest(string $key, ?string $reason = null): void
    {
        $spec = SiteFixers::spec($key);
        if ($spec === null) {
            return;
        }
        $this->remediations[$key] = ['key' => $key, 'label' => (string) $spec['label'], 'reason' => $reason ?? (string) $spec['reason']];
    }

    /**
     * Match the captured app-log error against the fixer registry — every
     * matching fixer becomes a one-click button — plus a little extra guidance
     * for causes that have no safe automated fix.
     */
    private function classifyLog(string $log, ConsoleEmitter $emit): void
    {
        foreach (SiteFixers::detect($log) as $hit) {
            $this->suggest($hit['key'], $hit['reason']);
        }

        if (preg_match('/SQLSTATE\[08006\]|Connection refused|could not connect to server|getaddrinfo|Name or service not known/i', $log) === 1) {
            $emit->step('fix', '→ The database/redis is unreachable from the server — check host, port, and that remote access is allowed for this server.');
        }
    }

    private function persistRemediations(Site $site, bool $ok): void
    {
        $meta = $site->meta;
        $meta['health'] = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'remediations' => array_values($this->remediations),
        ];
        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param-out bool $httpFailed
     */
    private function httpCheck(Site $site, ConsoleEmitter $emit, bool &$httpFailed): bool
    {
        $httpFailed = false;
        $url = $this->siteUrl($site);
        if ($url === null) {
            $emit->info('No URL is configured for this site yet — deploy it or set a domain first.', 'http');

            return false;
        }

        $emit->step('http', 'Requesting '.$url.' …');

        $status = 0;
        $transportError = null;
        try {
            $status = Http::timeout(20)->connectTimeout(10)->get($url)->status();
        } catch (\Throwable $e) {
            $transportError = mb_substr($e->getMessage(), 0, 300);
        }

        if ($transportError === null && $status >= 200 && $status < 400) {
            $emit->success('http', sprintf('HTTP %d — the site loaded.', $status));

            return true;
        }

        $httpFailed = true;
        $emit->error(
            $transportError !== null
                ? 'Could not reach the site: '.$transportError
                : sprintf('HTTP %d — the site returned an error and will not load for visitors.', $status),
            'http',
        );

        return false;
    }

    /**
     * Returns true when a boot-breaking server issue was found (missing DB
     * driver), so the overall run is marked failed.
     */
    private function serverChecks(Site $site, SshConnectionFactory $factory, ConsoleEmitter $emit, bool $tailLog): bool
    {
        if ($site->server === null) {
            return false;
        }

        $dir = rtrim($site->effectiveEnvDirectory(), '/');
        $logPath = $dir.'/storage/logs/laravel.log';
        $conn = null;
        $hardFailure = false;

        try {
            $emit->step('server', 'Inspecting the server (PHP drivers, config cache, built assets) …');
            $conn = $factory->forServer($site->server);
            if (! $conn->connect(12)) {
                $emit->info('Could not open SSH to inspect the server.', 'server');

                return false;
            }

            $probe = 'cd '.escapeshellarg($dir).' 2>/dev/null || { echo DPLY_NODIR; exit 0; }; '
                .'echo "DPLY_ENV_MTIME=$(stat -c %Y .env 2>/dev/null || echo 0)"; '
                .'echo "DPLY_CFG_MTIME=$(stat -c %Y bootstrap/cache/config.php 2>/dev/null || echo 0)"; '
                .'echo "DPLY_VITE_MANIFEST=$(test -f public/build/manifest.json && echo 1 || echo 0)"; '
                .'echo "DPLY_VITE_CONFIG=$( { test -f vite.config.js || test -f vite.config.ts; } && echo 1 || echo 0)"; '
                .'echo "DPLY_DBCONN=$(grep -aE \'^DB_CONNECTION=\' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d \'\"\' | tr -d \" \")"; '
                .'echo "DPLY_MODS=$(php -m 2>/dev/null | tr \"\n\" \",\")"';
            $out = $conn->exec($probe, 25);
            $vals = $this->parseProbe($out);

            if (($vals['DPLY_NODIR'] ?? false) === true) {
                $emit->info('The deploy directory was not found on the server yet — has it been deployed?', 'server');

                return false;
            }

            // (a) Missing PHP DB driver → "could not find driver".
            $dbConn = strtolower((string) ($vals['DBCONN'] ?? ''));
            $mods = strtolower((string) ($vals['MODS'] ?? ''));
            $needDriver = match ($dbConn) {
                'pgsql' => 'pdo_pgsql',
                'mysql', 'mariadb' => 'pdo_mysql',
                default => null,
            };
            if ($needDriver !== null && ! str_contains($mods, $needDriver)) {
                $hardFailure = true;
                $emit->error(sprintf('PHP is missing the %s extension — a %s app will fail with "could not find driver".', $needDriver, $dbConn), 'server');
                $this->suggest($dbConn === 'pgsql' ? 'install_pgsql_driver' : 'install_mysql_driver');
            } elseif ($needDriver !== null) {
                $emit->success('server', sprintf('PHP has the %s driver for the %s database.', $needDriver, $dbConn));
            }

            // (b) Stale config cache → app runs on old/empty values.
            $envMtime = (int) ($vals['ENV_MTIME'] ?? 0);
            $cfgMtime = (int) ($vals['CFG_MTIME'] ?? 0);
            if ($cfgMtime > 0 && $envMtime > $cfgMtime) {
                $emit->warn('The config cache is older than the .env — the app is running on stale config. Run `php artisan config:clear` (or redeploy) so new variables take effect.', 'server');
                $this->suggest('config_clear', 'The config cache is older than the .env.');
            }

            // (c) Front-end assets never built (the Vite manifest 500).
            if ((int) ($vals['VITE_CONFIG'] ?? 0) === 1 && (int) ($vals['VITE_MANIFEST'] ?? 0) === 0) {
                $emit->warn('No Vite manifest at public/build/manifest.json — front-end assets were not built. The app 500s rendering any @vite asset.', 'server');
                $this->suggest('build_assets');
            }

            if ($tailLog) {
                // Print the LATEST error entry from its header (the message),
                // not the trailing stack frames — find the last "[date] env.LEVEL:"
                // line and dump from there. Falls back to a plain tail.
                $logArg = escapeshellarg($logPath);
                $headerRe = escapeshellarg('^\[[0-9-]+ [0-9:]+\] [A-Za-z._-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):');
                $cmd = 'L=$(grep -anE '.$headerRe.' '.$logArg.' 2>/dev/null | tail -1 | cut -d: -f1); '
                    .'if [ -n "$L" ]; then sed -n "${L},$((L+120))p" '.$logArg.' 2>/dev/null; else tail -n 60 '.$logArg.' 2>/dev/null; fi';
                $log = rtrim($conn->exec($cmd, 25));
                if ($log !== '') {
                    $emit->step('log', "Latest app error (from the start):\n".$log);
                    $this->classifyLog($log, $emit);
                }
            }
        } catch (\Throwable $e) {
            $emit->info('Server inspection did not complete: '.mb_substr($e->getMessage(), 0, 200), 'server');
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        return $hardFailure;
    }

    /**
     * @return array<string, string|bool>
     */
    private function parseProbe(string $out): array
    {
        $vals = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === 'DPLY_NODIR') {
                $vals['DPLY_NODIR'] = true;

                continue;
            }
            if (str_starts_with($line, 'DPLY_') && str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $vals[substr($k, 5)] = $v; // strip the DPLY_ prefix
            }
        }

        return $vals;
    }

    private function siteUrl(Site $site): ?string
    {
        $host = trim($site->testingHostname());

        if ($host === '') {
            $domain = $site->primaryDomain();
            $host = trim((string) ($domain->domain ?? $domain->hostname ?? ''));
        }

        return $host === '' ? null : 'https://'.$host.'/';
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
