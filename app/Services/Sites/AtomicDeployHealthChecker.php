<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * After an atomic cutover, optionally verifies HTTP from the app server to 127.0.0.1 with the site Host header.
 */
final class AtomicDeployHealthChecker
{
    /**
     * Runs the configured health check and returns log lines to append, or an empty string if disabled.
     *
     * @throws \RuntimeException when enabled and checks fail
     */
    public function verify(Site $site, SshConnection $ssh): string
    {
        $meta = ($site->meta );

        // Validate-by-default: every atomic deploy is HTTP-smoke-tested after
        // cutover unless explicitly opted out (meta.deploy_health_enabled=false
        // or a global config flag). A deploy that renders a 5xx (missing build,
        // fatal boot, bad config) must NOT be reported as a success — the
        // pre-cutover TCP resource probe cannot see a render-time 500.
        if (($meta['deploy_health_enabled'] ?? config('deploy.health_check_default', true)) === false) {
            return '';
        }

        // Probe EVERY live surface — the primary domain(s) AND the testing
        // hostname (*.on-dply.<tld>). Probing only the first let a 500 on the
        // OTHER surface (e.g. the testing host while a primary domain exists, or
        // vice versa) sail through as a green deploy — exactly how a homepage 500
        // got past the gate. With no hostname at all there's nothing to
        // smoke-test yet — skip, don't fail.
        $hosts = $this->hostnamesToProbe($site);
        if ($hosts === []) {
            return "\n--- deploy health check ---\nskipped: site has no hostname to probe yet.\n";
        }

        // Default gate = "the app rendered a non-5xx response" against the
        // homepage — the real layout/assets, so a missing Vite manifest or boot
        // fatal is caught (a bare /up route can return 200 while every real page
        // 500s). An explicit deploy_health_expect_status keeps exact-match mode.
        $explicitExpect = isset($meta['deploy_health_expect_status']) && is_numeric($meta['deploy_health_expect_status']);
        $expect = $explicitExpect ? (int) $meta['deploy_health_expect_status'] : 0;

        // ALWAYS probe the homepage '/', plus any configured health path. A site
        // whose meta still carries deploy_health_path='/up' (Laravel's bare health
        // route) would otherwise pass while every real, Vite-rendering page 500s —
        // the exact gap that let a homepage 500 through. Probing '/' too closes it
        // with no per-site backfill.
        $configuredPath = $this->normalizePath((string) ($meta['deploy_health_path'] ?? '/'));
        $paths = array_values(array_unique(['/', $configuredPath]));
        $attempts = max(1, min(30, (int) ($meta['deploy_health_attempts'] ?? 5)));
        $delayMs = max(0, min(10000, (int) ($meta['deploy_health_delay_ms'] ?? 1000)));

        // Connect to the box itself; pin BOTH :80 and :443 of the hostname to it
        // and follow redirects (-L) so an http→https hop still lands here and the
        // FINAL status reflects what the app actually rendered. -k tolerates a
        // not-yet-valid cert on this loopback probe — we validate that the app
        // renders, not the TLS chain. Default scheme http so http-only sites work.
        $scheme = strtolower((string) ($meta['deploy_health_scheme'] ?? 'http'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'http';
        }
        $targetHost = trim((string) ($meta['deploy_health_host'] ?? '127.0.0.1'));
        if ($targetHost === '' || filter_var($targetHost, FILTER_VALIDATE_IP) === false) {
            $targetHost = '127.0.0.1';
        }

        $log = "\n--- deploy health check ---\n";
        $log .= 'Probing '.count($hosts).' hostname(s) × '.count($paths).' path(s): '
            .implode(', ', $hosts).' @ '.implode(', ', $paths)."\n";

        // Every host × path must pass; the first 5xx fails the whole deploy so a
        // broken surface can't hide behind a healthy sibling (or behind /up).
        foreach ($hosts as $hostHeader) {
            foreach ($paths as $probePath) {
                $result = $this->probeHost($ssh, $hostHeader, $probePath, $scheme, $targetHost, $attempts, $delayMs, $explicitExpect, $expect);
                $log .= $result['log'];

                if (! $result['ok']) {
                    $lastRaw = $result['lastRaw'];
                    $lastCode = preg_match('/^\d{3}$/', $lastRaw) ? $lastRaw : '';
                    $gotStr = $lastCode !== '' ? 'HTTP '.$lastCode : ($lastRaw !== '' ? $lastRaw : '(no response)');
                    $expectMsg = $explicitExpect ? __('expected HTTP :e', ['e' => $expect]) : __('expected a non-5xx response');
                    $url = $scheme.'://'.$hostHeader.$probePath;

                    // The thrown message carries the REAL cause pulled off the box
                    // (laravel.log tail, nginx error log, php-fpm state) so the deploy
                    // failure record shows *why* it 500'd — not just "got 500".
                    throw new \RuntimeException(
                        __('Deploy health check failed for :host after :n attempts — last response was :got (:expect).', [
                            'host' => $hostHeader.$probePath,
                            'n' => $attempts,
                            'got' => $gotStr,
                            'expect' => $expectMsg,
                        ])."\n\n".$this->diagnose($ssh, $site, $url, $hostHeader, $targetHost, $scheme === 'https' ? 443 : 80)
                    );
                }
            }
        }

        // Asset-integrity gate. The homepage rendered non-5xx above, but a
        // freshly-hashed Vite asset can still 404 when workers serve a STALE
        // release — OPcache pinned the old `current` realpath and a plain FPM
        // reload didn't clear it. That surfaces as a 200 HTML page whose
        // <link>/<script> point at build/assets/* that resolve to the branded
        // 404 (Content-Type text/html): a green health check over an unstyled
        // site. Pull the asset URLs the app actually rendered and assert each
        // returns 200 + a non-HTML MIME. Default-on; opt out per-site via
        // meta.deploy_asset_integrity_enabled=false or globally in config.
        if (($meta['deploy_asset_integrity_enabled'] ?? config('deploy.asset_integrity_default', true)) !== false) {
            $log .= $this->verifyAssets($site, $ssh, $scheme, $targetHost, $hosts);
        }

        return $log;
    }

    /**
     * Fetch the rendered homepage and assert every Vite build asset it
     * references serves as a real asset (200 + a stylesheet/script MIME). This
     * catches the "stale release" failure a 5xx probe can't: a 200 page whose
     * hashed CSS/JS 404s to the branded fallback (text/html), leaving the site
     * unstyled. Best-effort self-heal — on the first miss it flushes OPcache and
     * re-probes once (the fix is deterministic) before throwing to trigger the
     * deployer's auto-rollback.
     *
     * @param  list<string>  $hosts
     * @throws \RuntimeException when assets stay broken after the heal attempt
     */
    private function verifyAssets(Site $site, SshConnection $ssh, string $scheme, string $targetHost, array $hosts): string
    {
        $host = $hosts[0];
        $assets = $this->extractBuildAssets($ssh, $host, $scheme, $targetHost);

        $log = "\n--- asset integrity ---\n";
        if ($assets === []) {
            return $log.'no build/assets referenced by '.$host."/ — nothing to verify.\n";
        }

        $broken = $this->probeAssets($ssh, $host, $scheme, $targetHost, $assets);
        $log .= 'Checked '.count($assets).' asset(s) referenced by '.$host."/\n";

        if ($broken === []) {
            return $log."All assets return 200 + a stylesheet/script MIME.\n";
        }

        // The deterministic cause is a stale OPcache pin — heal once, re-probe.
        $log .= count($broken)." asset(s) broken (stale release?) — flushing OPcache and re-checking:\n";
        foreach ($broken as $b) {
            $log .= '  '.$b['path'].' → '.$b['reason']."\n";
        }
        $log .= '  '.trim(app(SiteOpcacheManager::class)->flushForDeploy($site))."\n";
        usleep(750 * 1000);

        $stillBroken = $this->probeAssets($ssh, $host, $scheme, $targetHost, array_column($broken, 'path'));
        if ($stillBroken === []) {
            return $log."Re-check passed — assets now serve correctly.\n";
        }

        $detail = implode("\n", array_map(fn (array $b): string => '  '.$b['path'].' → '.$b['reason'], $stillBroken));

        throw new \RuntimeException(
            __('Deploy asset-integrity check failed: :n asset(s) the homepage references do not serve (stale release / OPcache not cleared).', ['n' => count($stillBroken)])
            ."\n\n".$detail
        );
    }

    /**
     * GET the homepage over loopback and pull the distinct build/assets/* CSS
     * and JS paths the app rendered into its <link>/<script> tags. Returns the
     * PATH portion only (host-relative); capped so a chunk-heavy page can't
     * balloon the probe. Empty for non-Vite sites — a clean skip, not a failure.
     *
     * @return list<string>
     */
    private function extractBuildAssets(SshConnection $ssh, string $host, string $scheme, string $targetHost): array
    {
        $url = $scheme.'://'.$host.'/';
        $curl = 'curl -sS -L -k --max-time 25'
            .' --resolve '.escapeshellarg($host.':80:'.$targetHost)
            .' --resolve '.escapeshellarg($host.':443:'.$targetHost)
            .' '.escapeshellarg($url).' 2>/dev/null';
        $html = $ssh->exec($curl, 45);

        if (! preg_match_all('#/build/assets/[A-Za-z0-9_./-]+\.(?:css|js)#', $html, $m)) {
            return [];
        }

        return array_slice(array_values(array_unique($m[0])), 0, 20);
    }

    /**
     * HEAD each asset over loopback; return the ones that are NOT a clean 200 +
     * stylesheet/script MIME. A 404 or a `text/html` response means the box is
     * serving the branded fallback in place of the asset — the stale-release
     * signature. Uses the LAST status/Content-Type in the response so a
     * redirect chain reflects the final hop.
     *
     * @param  list<string>  $assets  asset paths
     * @return list<array{path: string, reason: string}>
     */
    private function probeAssets(SshConnection $ssh, string $host, string $scheme, string $targetHost, array $assets): array
    {
        $broken = [];

        foreach ($assets as $path) {
            $url = $scheme.'://'.$host.$path;
            $curl = 'curl -sS -I -L -k --max-time 20'
                .' --resolve '.escapeshellarg($host.':80:'.$targetHost)
                .' --resolve '.escapeshellarg($host.':443:'.$targetHost)
                .' '.escapeshellarg($url).' 2>&1';
            $head = $ssh->exec($curl, 30);

            $code = preg_match_all('#HTTP/[\d.]+\s+(\d{3})#', $head, $codes) ? (int) end($codes[1]) : 0;
            $ctype = preg_match_all('/Content-Type:\s*([^\r\n;]+)/i', $head, $ct) ? strtolower(trim((string) end($ct[1]))) : '';

            if ($code !== 200) {
                $broken[] = ['path' => $path, 'reason' => 'HTTP '.($code !== 0 ? $code : '(no response)')];
            } elseif (str_contains($ctype, 'text/html')) {
                $broken[] = ['path' => $path, 'reason' => '200 but MIME '.($ctype !== '' ? $ctype : 'unknown').' (served the fallback page, not the asset)'];
            }
        }

        return $broken;
    }

    /**
     * The distinct live hostnames to smoke-test: the primary domain plus the
     * testing hostname (both, when both exist). Lowercased + de-duplicated.
     *
     * @return list<string>
     */
    private function hostnamesToProbe(Site $site): array
    {
        $candidates = [];

        $domain = $site->primaryDomain();
        if ($domain !== null && trim((string) $domain->hostname) !== '') {
            $candidates[] = strtolower(trim((string) $domain->hostname));
        }

        $testing = strtolower(trim($site->testingHostname()));
        if ($testing !== '') {
            $candidates[] = $testing;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Probe a single hostname against the box over loopback, retrying up to
     * $attempts. Returns whether it passed, the per-host log lines, and the last
     * raw curl output (for the failure diagnostic).
     *
     * @return array{ok: bool, log: string, lastRaw: string}
     */
    private function probeHost(
        SshConnection $ssh,
        string $hostHeader,
        string $path,
        string $scheme,
        string $targetHost,
        int $attempts,
        int $delayMs,
        bool $explicitExpect,
        int $expect,
    ): array {
        $url = $scheme.'://'.$hostHeader.$path;

        $curl = 'curl -sS -L -k --max-time 25 -o /dev/null -w \'%{http_code}\''
            .' --resolve '.escapeshellarg($hostHeader.':80:'.$targetHost)
            .' --resolve '.escapeshellarg($hostHeader.':443:'.$targetHost)
            .' '.escapeshellarg($url).' 2>&1';

        $log = 'GET '.$url.' (resolve '.$hostHeader.' → '.$targetHost.", follow redirects)\n";
        $log .= ($explicitExpect ? '  expect HTTP '.$expect : '  expect a non-5xx response')
            .', up to '.$attempts." attempt(s)\n";

        $lastRaw = '';

        for ($i = 1; $i <= $attempts; $i++) {
            $raw = trim($ssh->exec($curl, 45));
            $lastRaw = $raw;
            $code = preg_match('/^\d{3}$/', $raw) ? (int) $raw : 0;

            // Default: pass on any non-5xx (the app rendered). Explicit: exact match.
            $ok = $explicitExpect ? ($code === $expect) : ($code >= 100 && $code < 500);
            if ($ok) {
                $log .= '  attempt '.$i.': HTTP '.$code." (ok)\n";

                return ['ok' => true, 'log' => $log, 'lastRaw' => $lastRaw];
            }

            $log .= '  attempt '.$i.': got '.($raw !== '' ? $raw : '(empty)')
                .($explicitExpect ? " (expected {$expect})" : ' (5xx / no response)')."\n";

            if ($i < $attempts && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['ok' => false, 'log' => $log, 'lastRaw' => $lastRaw];
    }

    /**
     * Gather the real cause from the server so the failure is self-explanatory.
     * For a 5xx the source of truth is: the nginx error log (the exact upstream
     * failure — "connect() failed", "no input file", bad socket), the PHP-FPM
     * unit state + its journal, `php -v` / `php-fpm -t` (extension/config fatals),
     * and the site's own laravel.log (an app exception). Privileged probes use
     * `sudo -n` and fall back; every probe is guarded so one missing source never
     * hides the rest. The response body itself is dropped — on a 502 it's only
     * dply's branded fallback page, which says nothing about the cause.
     */
    private function diagnose(SshConnection $ssh, Site $site, string $url, string $hostHeader, string $targetHost, int $port): string
    {
        // Use the site's REAL deploy root (repository_path), not the naming
        // convention — they differ when a site was bootstrapped at a custom path
        // (e.g. the control plane at /home/dply/dply, not /home/dply/<domain>),
        // which otherwise makes the "current → MISSING" line a false alarm.
        $repo = rtrim($site->effectiveRepositoryPath(), '/');
        $resolveArg = '--resolve '.$this->sh($hostHeader.':'.$port.':'.$targetHost);
        // Candidate Laravel log locations across release layouts.
        $logCandidates = implode(' ', array_map(fn ($p) => $this->sh($repo.$p), [
            '/current/storage/logs/laravel.log',
            '/storage/logs/laravel.log',
            '/shared/storage/logs/laravel.log',
        ]));

        // The nginx error-log filter is an ERE alternation, so the host (which is
        // user-controlled) must be regex-escaped before it joins the pattern —
        // otherwise a metachar in the hostname would alter the match. The whole
        // pattern then ships as one escapeshellarg-quoted argument.
        $logFilter = $this->ereLiteral($hostHeader)
            .'|fastcgi|upstream|connect\\(\\)|No such file|Primary script';

        $script = <<<BASH
echo "── response headers ──"
curl -sS --max-time 20 -o /dev/null -D - {$resolveArg} {$this->sh($url)} 2>&1 | head -n 12

echo
echo "── php cli (extension/load fatals) ──"
php -v 2>&1 | head -n 6
printf 'redis loaded: %s\n' "\$(php -m 2>/dev/null | grep -qi '^redis\$' && echo yes || echo NO)"

echo
echo "── php-fpm ──"
units=\$(systemctl list-units --type=service --all --no-legend 'php*-fpm*' 2>/dev/null | awk '{print \$1}')
[ -z "\$units" ] && units=\$(ls -1 /etc/php/ 2>/dev/null | sed 's/.*/php&-fpm.service/')
for u in \$units; do
  printf '%s: active=%s\n' "\$u" "\$(systemctl is-active "\$u" 2>/dev/null || echo unknown)"
  ver=\$(echo "\$u" | grep -oE '[0-9]+\.[0-9]+')
  [ -n "\$ver" ] && { command -v "php-fpm\${ver}" >/dev/null 2>&1 && (sudo -n "php-fpm\${ver}" -t 2>&1 || "php-fpm\${ver}" -t 2>&1) | tail -n 4; }
  (sudo -n journalctl -u "\$u" -n 15 --no-pager 2>/dev/null | tail -n 15) || echo "  (journal needs privileges)"
done

echo "── nginx config + site vhost ──"
(sudo -n nginx -t 2>&1 || nginx -t 2>&1 || echo "(nginx -t needs privileges)") | tail -n 3
printf 'enabled vhost for %s: ' {$this->sh($hostHeader)}
match=\$( (sudo -n grep -rls {$this->sh($hostHeader)} /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null || grep -rls {$this->sh($hostHeader)} /etc/nginx/sites-enabled 2>/dev/null) | paste -sd' ' - )
[ -n "\$match" ] && echo "\$match" || echo "NONE — request falls through to the default server (the 502 page)"

echo "── release / docroot ──"
printf 'current -> %s\n' "\$(readlink -f {$this->sh($repo)}/current 2>/dev/null || echo MISSING)"
ls -la {$this->sh($repo)}/current/public/index.php 2>/dev/null || echo "  no current/public/index.php — release not activated"

echo "── nginx error log (recent) ──"
(sudo -n tail -n 40 /var/log/nginx/error.log 2>/dev/null || tail -n 40 /var/log/nginx/error.log 2>/dev/null || echo "(no access to /var/log/nginx/error.log)") | grep -E {$this->sh($logFilter)} | tail -n 10

echo "── site laravel.log (last error, full trace) ──"
for p in {$logCandidates}; do
  if [ -f "\$p" ]; then
    echo "\$p:"
    # Print the LAST complete log entry in full — from its [timestamp] header
    # through every stack frame — so the exception class/message at the top is
    # never lost to a fixed tail. Reset the buffer on each new entry header
    # ([20xx-..]); emit whatever's buffered at EOF. Capped so a runaway trace
    # can't flood the diagnostic.
    awk '/^\[20[0-9][0-9]-/{buf=""} {buf=buf \$0 ORS} END{printf "%s", buf}' "\$p" | head -n 500
    break
  fi
done
BASH;

        $out = trim($ssh->exec($script, 90));

        return $out !== ''
            ? "Server diagnostics:\n".$out
            : 'Server diagnostics: (could not gather — SSH probe returned nothing)';
    }

    /** Shell-quote for embedding in the diagnostic heredoc. */
    private function sh(string $value): string
    {
        return escapeshellarg($value);
    }

    /** Escape POSIX ERE metacharacters so $value matches literally inside a grep -E pattern. */
    private function ereLiteral(string $value): string
    {
        return preg_replace('/[.^$*+?()\[\]{}|\\\\]/', '\\\\$0', $value);
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            return '/'.$path;
        }

        return $path;
    }
}
