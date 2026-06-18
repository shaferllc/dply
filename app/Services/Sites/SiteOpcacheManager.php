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
        $script = sprintf(
            <<<'BASH'
AGENT=%s
printf '%%s' %s | base64 -d > "$AGENT"
chmod 644 "$AGENT"
PHPBIN=%s
if [ ! -x "$PHPBIN" ]; then PHPBIN="$(command -v php || true)"; fi
if [ -z "$PHPBIN" ]; then echo '{"ok":false,"error":"no php binary"}'; rm -f "$AGENT"; exit 0; fi
"$PHPBIN" "$AGENT" %s %s 2>/dev/null
rm -f "$AGENT"
BASH,
            escapeshellarg($agentPath),
            escapeshellarg($b64),
            escapeshellarg("/usr/bin/php{$version}"),
            escapeshellarg($socket),
            escapeshellarg($action),
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

        return is_array($decoded) ? $decoded : null;
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
$s = @opcache_get_status(false);
if ($s === false) { echo json_encode(['ok' => true, 'enabled' => false, 'reason' => 'disabled-in-fpm']); exit; }
$mem = $s['memory_usage'] ?? [];
$stats = $s['opcache_statistics'] ?? [];
$hits = (int) ($stats['hits'] ?? 0);
$misses = (int) ($stats['misses'] ?? 0);
$total = $hits + $misses;
echo json_encode([
    'ok' => true,
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
