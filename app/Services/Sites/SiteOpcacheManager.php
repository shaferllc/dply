<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Modules\Insights\Services\Runners\OpcacheFullInsightRunner;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads and resets a site's PHP-FPM OPcache.
 *
 * OPcache lives in the FPM master's shared memory (per PHP version, shared by
 * that version's pools). A separate `php` CLI process gets its OWN empty cache
 * and can neither see nor reset FPM's — which is why the existing
 * {@see OpcacheFullInsightRunner} CLI probe is
 * only best-effort. To touch the real cache we must run inside an FPM worker.
 *
 * `cachetool`/`cgi-fcgi` aren't installed on dply boxes, so we ship a tiny
 * self-dispatching agent: run from the CLI it speaks the FastCGI protocol to
 * the site's dedicated pool socket and requests ITSELF, so the body executes in
 * a real worker and reports/clears the live cache. The agent is written to
 * /tmp (mode 644 so the pool user can read it), run, and removed in one SSH
 * roundtrip. Validated against a live FPM socket before shipping.
 */
final class SiteOpcacheManager
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array<string, mixed>|null Parsed agent JSON, or null when the
     *                                   probe couldn't run / reach FPM.
     */
    public function status(Site $site): ?array
    {
        return $this->run($site, 'status');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reset(Site $site): ?array
    {
        return $this->run($site, 'reset');
    }

    /**
     * Flush OPcache after a deploy cutover, returning a one-line deploy-log
     * message describing the outcome.
     *
     * An FPM *reload* (the deploy's managed restart) re-reads pool config but
     * leaves the OPcache shared memory intact. On dply boxes
     * `opcache.revalidate_path` is off, so workers keep serving the PRIOR
     * release's cached bytecode AND its resolved `current/public/index.php`
     * realpath even after the atomic symlink swap — the classic "deployed but
     * still serving old code" failure (e.g. a stale Vite asset hash 404'ing
     * because the manifest the app reads is the old release's). {@see reset()}
     * runs `opcache_reset()` inside a live worker, which is what actually clears
     * it — no full `systemctl restart`, no cross-pool blip.
     *
     * Best-effort by contract: never throws, and a no-op (empty string) for
     * shared-pool sites (Apache/OpenLiteSpeed), so a flush can never fail an
     * otherwise-healthy deploy.
     */
    public function flushForDeploy(Site $site): string
    {
        if (! $site->usesDedicatedPhpFpmPool()) {
            return '';
        }

        try {
            $result = $this->reset($site);
        } catch (\Throwable $e) {
            return "[dply] OPcache flush skipped/failed (continuing): {$e->getMessage()}\n";
        }

        if ($result === null || ($result['ok'] ?? false) !== true) {
            return "[dply] OPcache flush: pool unreachable — new release picked up on next FPM restart.\n";
        }

        if (($result['reset'] ?? null) === false) {
            return "[dply] OPcache not enabled for {$site->phpFpmPoolName()} — nothing to flush.\n";
        }

        return "[dply] OPcache flushed for {$site->phpFpmPoolName()} — new release live.\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function run(Site $site, string $action): ?array
    {
        $server = $site->server;
        if ($server === null || ! $site->usesDedicatedPhpFpmPool()) {
            return null;
        }

        $socket = $site->phpFpmListenSocketPath();
        $version = $site->resolvedPhpFpmVersion();
        $agentPath = '/tmp/.dply-opcache-'.Str::lower(Str::random(24)).'.php';

        $b64 = base64_encode($this->agentScript());
        // The FPM pool socket is owned by the pool user with listen.mode 0660,
        // so the `dply` SSH user can't connect to it directly ("Permission
        // denied") — root can (it bypasses the socket's perms). Try `sudo -n`
        // first and fall back to a direct run on boxes without passwordless
        // sudo, so a permission-only failure never blanks the whole probe.
        $script = sprintf(
            <<<'BASH'
AGENT=%s
printf '%%s' %s | base64 -d > "$AGENT"
chmod 644 "$AGENT"
PHPBIN=%s
if [ ! -x "$PHPBIN" ]; then PHPBIN="$(command -v php || true)"; fi
if [ -z "$PHPBIN" ]; then echo '{"ok":false,"error":"no php binary"}'; rm -f "$AGENT"; exit 0; fi
SOCK=%s
ACTION=%s
OUT="$(sudo -n "$PHPBIN" "$AGENT" "$SOCK" "$ACTION" 2>/dev/null)"
case "$OUT" in
  *'"ok":true'*) : ;;
  *) OUT="$("$PHPBIN" "$AGENT" "$SOCK" "$ACTION" 2>/dev/null)" ;;
esac
printf '%%s' "$OUT"
rm -f "$AGENT"
CURREL="$(readlink -f %s 2>/dev/null)"; CURREL="${CURREL##*/}"
printf '\nDPLY_CURRENT=%%s\n' "$CURREL"
BASH,
            escapeshellarg($agentPath),
            escapeshellarg($b64),
            escapeshellarg("/usr/bin/php{$version}"),
            escapeshellarg($socket),
            escapeshellarg($action),
            escapeshellarg(rtrim($site->effectiveRepositoryPath(), '/').'/current'),
        );

        try {
            $out = $this->remote->runInlineBash($server, 'site-opcache-'.$action, $script, 30, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('sites.opcache_'.$action.'_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        }

        // The agent always prints a single JSON object; pull it out of any
        // surrounding noise the shell may have emitted.
        if (! preg_match('/\{.*\}/s', $buffer, $m)) {
            return null;
        }

        $decoded = json_decode($m[0], true);
        if (! is_array($decoded)) {
            return null;
        }

        // The `current` symlink target folder — the ground truth for "what
        // should be live". Compared against `serving_release`, a mismatch is the
        // real worker pin (the DB SiteRelease row can lag actual deploys, so it
        // is NOT a reliable baseline). Captured in the same round-trip.
        if (preg_match('/DPLY_CURRENT=(\S+)/', $buffer, $cm) && $cm[1] !== '') {
            $decoded['current_release'] = $cm[1];
        }

        return $decoded;
    }

    /**
     * The self-dispatching FastCGI client / OPcache responder. Kept inline (not
     * a stub file) so the deployed payload is auditable in one place.
     */
    private function agentScript(): string
    {
        return <<<'PHP'
<?php
if (PHP_SAPI === 'cli') {
    $socket = $argv[1] ?? '';
    $action = $argv[2] ?? 'status';
    $self = __FILE__;
    if ($socket === '') { fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'no socket'])); exit(0); }
    $remote = str_contains($socket, '/') ? 'unix://'.$socket : 'tcp://'.$socket;
    $errno = 0; $errstr = '';
    $conn = @stream_socket_client($remote, $errno, $errstr, 5);
    if (! $conn) { fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'connect: '.$errstr])); exit(0); }
    stream_set_timeout($conn, 5);
    $rid = 1;
    $rec = static fn (int $t, string $c): string => pack('CCnnCC', 1, $t, $rid, strlen($c), 0, 0).$c;
    $len = static fn (int $n): string => $n < 128 ? chr($n) : pack('N', $n | 0x80000000);
    $pair = static fn (string $k, string $v): string => $len(strlen($k)).$len(strlen($v)).$k.$v;
    fwrite($conn, $rec(1, pack('nCxxxxx', 1, 0)));
    $params = [
        'GATEWAY_INTERFACE' => 'FastCGI/1.0', 'REQUEST_METHOD' => 'GET',
        'SCRIPT_FILENAME' => $self, 'SCRIPT_NAME' => '/'.basename($self),
        'REQUEST_URI' => '/'.basename($self), 'DOCUMENT_ROOT' => dirname($self),
        'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_SOFTWARE' => 'dply-opcache-agent',
        'REMOTE_ADDR' => '127.0.0.1', 'QUERY_STRING' => '', 'DPLY_OPCACHE_ACTION' => $action,
    ];
    $payload = '';
    foreach ($params as $k => $v) { $payload .= $pair((string) $k, (string) $v); }
    fwrite($conn, $rec(4, $payload));
    fwrite($conn, $rec(4, ''));
    fwrite($conn, $rec(5, ''));
    $stdout = '';
    while (! feof($conn)) {
        $header = '';
        while (strlen($header) < 8) {
            $chunk = fread($conn, 8 - strlen($header));
            if ($chunk === '' || $chunk === false) { break 2; }
            $header .= $chunk;
        }
        if (strlen($header) < 8) { break; }
        $h = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);
        $toRead = $h['contentLength'] + $h['paddingLength'];
        $content = '';
        while (strlen($content) < $toRead) {
            $chunk = fread($conn, $toRead - strlen($content));
            if ($chunk === '' || $chunk === false) { break; }
            $content .= $chunk;
        }
        $body = substr($content, 0, $h['contentLength']);
        if ($h['type'] === 6) { $stdout .= $body; }
        elseif ($h['type'] === 3) { break; }
    }
    fclose($conn);
    $pos = strpos($stdout, "\r\n\r\n");
    if ($pos !== false) { $stdout = substr($stdout, $pos + 4); }
    else { $pos = strpos($stdout, "\n\n"); if ($pos !== false) { $stdout = substr($stdout, $pos + 2); } }
    if (trim($stdout) === '') { fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'empty FPM response'])); exit(0); }
    fwrite(STDOUT, trim($stdout));
    exit(0);
}

header('Content-Type: application/json');
$action = $_SERVER['DPLY_OPCACHE_ACTION'] ?? 'status';
if (! function_exists('opcache_get_status')) { echo json_encode(['ok' => true, 'enabled' => false, 'reason' => 'extension-missing']); exit; }
if ($action === 'reset') {
    $did = function_exists('opcache_reset') ? @opcache_reset() : false;
    echo json_encode(['ok' => true, 'reset' => (bool) $did]);
    exit;
}
$s = @opcache_get_status(true);
if ($s === false) { echo json_encode(['ok' => true, 'enabled' => false, 'reason' => 'disabled-in-fpm']); exit; }
$mem = $s['memory_usage'] ?? [];
$stats = $s['opcache_statistics'] ?? [];
$hits = (int) ($stats['hits'] ?? 0);
$misses = (int) ($stats['misses'] ?? 0);
$total = $hits + $misses;
// Which release are the LIVE workers actually booted from? The cached-script
// realpaths carry the atomic-deploy `releases/<folder>/…` segment; the most
// common one is the release these workers serve. Compared against the `current`
// symlink target, a mismatch IS the "deployed but serving old code" pin. Derived
// here so only the short folder string crosses the wire, never the script map.
$servingRelease = null;
if (isset($s['scripts']) && is_array($s['scripts'])) {
    $counts = [];
    foreach ($s['scripts'] as $path => $info) {
        if (preg_match('#/releases/([^/]+)/#', (string) $path, $mm)) { $counts[$mm[1]] = ($counts[$mm[1]] ?? 0) + 1; }
    }
    if ($counts) { arsort($counts); $servingRelease = (string) array_key_first($counts); }
}
echo json_encode([
    'ok' => true,
    'serving_release' => $servingRelease,
    'enabled' => (bool) ($s['opcache_enabled'] ?? false),
    'full' => (bool) ($s['cache_full'] ?? false),
    'restart_pending' => (bool) ($s['restart_pending'] ?? false),
    'memory_used' => (int) ($mem['used_memory'] ?? 0),
    'memory_free' => (int) ($mem['free_memory'] ?? 0),
    'memory_wasted' => (int) ($mem['wasted_memory'] ?? 0),
    'num_cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
    'num_cached_keys' => (int) ($stats['num_cached_keys'] ?? 0),
    'max_cached_keys' => (int) ($stats['max_cached_keys'] ?? 0),
    'hits' => $hits,
    'misses' => $misses,
    'hit_rate' => $total > 0 ? round($hits / $total * 100, 1) : null,
    'oom_restarts' => (int) ($stats['oom_restarts'] ?? 0),
    'hash_restarts' => (int) ($stats['hash_restarts'] ?? 0),
    'manual_restarts' => (int) ($stats['manual_restarts'] ?? 0),
    'last_restart_time' => (int) ($stats['last_restart_time'] ?? 0),
]);
PHP;
    }
}
