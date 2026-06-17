<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes OpenLiteSpeed's live state by SSH-reading its on-disk configs +
 * .rtreport runtime stats files, then parsing into the four sub-tab unit
 * arrays consumed by the OLS engine workspace:
 *
 *   - vhosts:    /usr/local/lsws/conf/vhosts/<name>/vhconf.conf parsed
 *                for docRoot, vhDomain, scripthandler/extprocessor (PHP
 *                version), plus per-vhost .rtreport-derived stats.
 *   - listeners: /usr/local/lsws/conf/httpd_config.conf parsed for
 *                `listener <name> { address ... secure ... map ... }`
 *                blocks.
 *   - extapps:   parsed extprocessor blocks across all vhost configs +
 *                check for the lsphp binaries under /usr/local/lsws.
 *   - cache:     per-vhost cache stats derived from .rtreport
 *                (PUB_CACHE_HITS, PRIVATE_CACHE_HITS).
 *
 * All parsing is forgiving — malformed or missing inputs produce an
 * empty units array for that sub-tab rather than throwing. Errors
 * surface in engineSpecific['errors'].
 */
class OlsLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    /**
     * Markers used to delimit sections inside the combined SSH output.
     * Bash echos these between cat-blocks; PHP splits on them.
     */
    private const HEAD_HTTPD = '###dply-section:httpd###';

    private const HEAD_VHOSTS = '###dply-section:vhosts###';

    private const HEAD_RTREPORT = '###dply-section:rtreport###';

    private const HEAD_LSPHP = '###dply-section:lsphp###';

    private const HEAD_END = '###dply-section:end###';

    public function engineKey(): string
    {
        return 'openlitespeed';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript();
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('Probe SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $vhconfs = $this->parseVhostConfFiles($sections['vhosts'] ?? '');
        $rtreport = $this->parseRtReportFiles($sections['rtreport'] ?? '');
        $listeners = $this->parseListeners($sections['httpd'] ?? '');
        $lsphpBins = $this->parseLsphpList($sections['lsphp'] ?? '');

        $vhosts = $this->buildVhostUnits($vhconfs, $rtreport);
        $extapps = $this->buildExtAppUnits($vhconfs, $lsphpBins);
        $cache = $this->buildCacheUnits($rtreport);

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'vhosts' => $vhosts,
                'listeners' => $listeners,
                'extapps' => $extapps,
                'cache' => $cache,
            ],
            engineSpecific: $errors === [] ? [] : ['errors' => $errors],
        );
    }

    /**
     * One bash script that cats everything we need with section markers,
     * letting PHP demux the output. Cheaper than 4 separate SSH round
     * trips and atomic-ish (single connection, single time stamp).
     */
    private function buildProbeScript(): string
    {
        $head = [
            'httpd' => self::HEAD_HTTPD,
            'vhosts' => self::HEAD_VHOSTS,
            'rtreport' => self::HEAD_RTREPORT,
            'lsphp' => self::HEAD_LSPHP,
        ];
        $end = self::HEAD_END;

        return <<<BASH
set +e
echo '{$head['httpd']}'
cat /usr/local/lsws/conf/httpd_config.conf 2>/dev/null
echo '{$end}'
echo '{$head['vhosts']}'
for f in /usr/local/lsws/conf/vhosts/*/vhconf.conf; do
  [ -e "\$f" ] || continue
  echo "###dply-file:\$f###"
  cat "\$f" 2>/dev/null
done
echo '{$end}'
echo '{$head['rtreport']}'
for f in /tmp/lshttpd/.rtreport*; do
  [ -e "\$f" ] || continue
  echo "###dply-file:\$f###"
  cat "\$f" 2>/dev/null
done
echo '{$end}'
echo '{$head['lsphp']}'
ls /usr/local/lsws/lsphp*/bin/lsphp 2>/dev/null
echo '{$end}'
BASH;
    }

    /**
     * Demux the combined output. Returns map of section → raw body
     * (between the head marker and the matching end marker).
     *
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = [
            'httpd' => self::HEAD_HTTPD,
            'vhosts' => self::HEAD_VHOSTS,
            'rtreport' => self::HEAD_RTREPORT,
            'lsphp' => self::HEAD_LSPHP,
        ];
        $end = self::HEAD_END;
        $out = [];
        foreach ($heads as $key => $head) {
            $startPos = strpos($output, $head);
            if ($startPos === false) {
                continue;
            }
            $startPos += strlen($head);
            $endPos = strpos($output, $end, $startPos);
            $out[$key] = $endPos === false
                ? substr($output, $startPos)
                : substr($output, $startPos, $endPos - $startPos);
        }

        return $out;
    }

    /**
     * Demux a vhosts blob (multiple files separated by `###dply-file:<path>###`
     * markers) into a map of file-path → file body.
     *
     * @return array<string, string>
     */
    private function splitByFileMarker(string $blob): array
    {
        $parts = preg_split('/^###dply-file:([^#]+)###$/m', $blob, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            return [];
        }
        $out = [];
        // splits start with anything before the first marker (probably blank).
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $path = trim($parts[$i]);
            $body = $parts[$i + 1];
            if ($path !== '') {
                $out[$path] = $body;
            }
        }

        return $out;
    }

    /**
     * Parse each vhconf.conf into a structured array. Captures the
     * top-level keys we care about + the nested extprocessor blocks.
     *
     * @return array<string, array{name: string, doc_root: ?string, domains: list<string>, php_version: ?string, extprocessors: list<array{name: string, type: ?string, path: ?string}>, ssl: bool}>
     */
    private function parseVhostConfFiles(string $vhostsBlob): array
    {
        $files = $this->splitByFileMarker($vhostsBlob);
        $out = [];
        foreach ($files as $path => $body) {
            // /usr/local/lsws/conf/vhosts/<name>/vhconf.conf
            if (! preg_match('#/vhosts/([^/]+)/vhconf\.conf$#', $path, $m)) {
                continue;
            }
            $name = $m[1];
            $docRoot = $this->extractTopLevelDirective($body, 'docRoot');
            $vhDomain = $this->extractTopLevelDirective($body, 'vhDomain');
            $domains = $vhDomain !== null ? array_values(array_filter(array_map('trim', explode(',', $vhDomain)))) : [];
            $extprocessors = $this->extractExtProcessors($body);
            $phpVersion = $this->derivePhpVersionFromExtProcessors($extprocessors);
            $hasSsl = preg_match('/^\s*vhssl\s*\{/mi', $body) === 1;

            $out[$name] = [
                'name' => $name,
                'doc_root' => $docRoot,
                'domains' => $domains,
                'php_version' => $phpVersion,
                'extprocessors' => $extprocessors,
                'ssl' => $hasSsl,
            ];
        }

        return $out;
    }

    /**
     * Top-level OLS directive: `key value` outside any `{...}` block. We
     * approximate with a line-level regex that's robust enough for the
     * dply-generated configs (which don't put the docRoot/vhDomain
     * inside nested blocks).
     */
    private function extractTopLevelDirective(string $body, string $key): ?string
    {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s+(.+?)\s*$/m', $body, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * `extprocessor <name> { type ... path ... }` blocks. We only need
     * type + path; the `path` for a `lsapi` entry is the lsphp binary
     * which tells us the PHP version.
     *
     * @return list<array{name: string, type: ?string, path: ?string}>
     */
    private function extractExtProcessors(string $body): array
    {
        // Match `extprocessor <name> {  ... }` non-greedy.
        if (! preg_match_all('/extprocessor\s+(\S+)\s*\{(.+?)\}/s', $body, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $name = trim($m[1]);
            $inner = $m[2];
            $type = null;
            $path = null;
            if (preg_match('/^\s*type\s+(\S+)/m', $inner, $tm)) {
                $type = trim($tm[1]);
            }
            if (preg_match('/^\s*path\s+(\S+)/m', $inner, $pm)) {
                $path = trim($pm[1]);
            }
            $out[] = ['name' => $name, 'type' => $type, 'path' => $path];
        }

        return $out;
    }

    /**
     * Derive a human PHP version like "8.3" from an extprocessor path
     * like "/usr/local/lsws/lsphp83/bin/lsphp". Returns null when no
     * lsphpXX path is present.
     *
     * @param  array<string, mixed> $extprocessors
     */
    private function derivePhpVersionFromExtProcessors(array $extprocessors): ?string
    {
        foreach ($extprocessors as $ep) {
            $path = (string) ($ep['path'] ?? '');
            if (preg_match('#/lsphp(\d)(\d+)/bin/lsphp#', $path, $m)) {
                return $m[1].'.'.$m[2];
            }
        }

        return null;
    }

    /**
     * .rtreport files: each contains comma-separated key:value pairs,
     * sometimes nested. We bucket by file (one OLS worker writes one
     * .rtreport.<N>) and aggregate into per-vhost stats when the file
     * names reference vhosts (some OLS builds split per-vhost).
     *
     * For dply v1 we just emit a single "all-vhosts" stats blob and
     * surface its keys in engineSpecific so the UI can render summaries.
     * Per-vhost cache hits land under the cache sub-tab when OLS
     * structures them that way.
     *
     * @return array<string, array<string, float|int>>
     */
    private function parseRtReportFiles(string $rtreportBlob): array
    {
        $files = $this->splitByFileMarker($rtreportBlob);
        $out = [];
        foreach ($files as $path => $body) {
            $stats = $this->parseRtReportBody($body);
            if ($stats !== []) {
                $out[$path] = $stats;
            }
        }

        return $out;
    }

    /**
     * @return array<string, float|int>
     */
    private function parseRtReportBody(string $body): array
    {
        $stats = [];
        foreach (explode("\n", $body) as $line) {
            // Lines like `PLAINCONN: 0, AVAILCONN: 10000, IDLECONN: 0`.
            // Strip the bracketed prefix in `REQ_RATE []: ...`.
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^([A-Z_]+)\s*\[[^\]]*\]\s*:/', '$1:', $line) ?? $line;
            foreach (explode(',', $line) as $piece) {
                $piece = trim($piece);
                if ($piece === '' || strpos($piece, ':') === false) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode(':', $piece, 2));
                if ($k === '' || $v === '') {
                    continue;
                }
                if (is_numeric($v)) {
                    $stats[$k] = (str_contains($v, '.') ? (float) $v : (int) $v);
                }
            }
        }

        return $stats;
    }

    /**
     * Parse listener blocks from httpd_config.conf. Each block:
     *   listener Default {
     *     address                 *:80
     *     secure                  0
     *     map                     site1 example.com
     *     map                     site2 another.com
     *   }
     *
     * @return list<array{name: string, address: ?string, secure: bool, vhosts: list<string>}>
     */
    private function parseListeners(string $httpdBody): array
    {
        if (! preg_match_all('/listener\s+(\S+)\s*\{(.+?)\}/s', $httpdBody, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $name = trim($m[1]);
            $inner = $m[2];
            $address = null;
            if (preg_match('/^\s*address\s+(\S+)/m', $inner, $am)) {
                $address = trim($am[1]);
            }
            $secure = false;
            if (preg_match('/^\s*secure\s+(\S+)/m', $inner, $sm)) {
                $secure = trim($sm[1]) === '1';
            }
            $vhosts = [];
            if (preg_match_all('/^\s*map\s+(\S+)/m', $inner, $vm)) {
                $vhosts = array_values(array_map('trim', $vm[1]));
            }
            $out[] = [
                'name' => $name,
                'address' => $address,
                'secure' => $secure,
                'vhosts' => $vhosts,
            ];
        }

        return $out;
    }

    /**
     * Extract `lsphpXX` versions from a newline-separated ls listing.
     *
     * @return list<string>
     */
    private function parseLsphpList(string $blob): array
    {
        $out = [];
        foreach (explode("\n", $blob) as $line) {
            $line = trim($line);
            if (preg_match('#/lsphp(\d{2,3})/bin/lsphp$#', $line, $m)) {
                $out[] = (int) $m[1] < 100
                    ? substr($m[1], 0, 1).'.'.substr($m[1], 1)
                    : $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Combine per-vhost configs + .rtreport stats into the Vhosts sub-tab
     * unit array. .rtreport keys that look vhost-scoped are matched on
     * a best-effort basis; OLS doesn't always split stats per-vhost, so
     * many rows will have stats === null.
     *
     * @param  array<string, array<string, mixed>>  $vhconfs
     * @param  array<string, array<string, float|int>>  $rtreport
     * @return list<array<string, mixed>>
     */
    private function buildVhostUnits(array $vhconfs, array $rtreport): array
    {
        // For v1 we aggregate the rtreport across all files into one
        // server-wide stats blob; per-vhost OLS rtreport is build-version
        // dependent. Future expansion: when .rtreport files contain
        // VHOST: <name> sections, demux them here.
        $aggregate = [];
        foreach ($rtreport as $stats) {
            foreach ($stats as $k => $v) {
                $aggregate[$k] = ($aggregate[$k] ?? 0) + $v;
            }
        }

        $rows = [];
        foreach ($vhconfs as $name => $cfg) {
            $rows[] = [
                'name' => $name,
                'doc_root' => $cfg['doc_root'],
                'domains' => $cfg['domains'],
                'php_version' => $cfg['php_version'],
                'ssl' => $cfg['ssl'],
                'extprocessor_count' => count($cfg['extprocessors']),
                // Per-vhost stats not available in v1; surface server-wide
                // counters once on the first vhost as a hint to the UI.
                // The UI's stats footer reads from engineSpecific, so this
                // is just convenience.
                'server_aggregate' => $aggregate,
            ];
        }

        return $rows;
    }

    /**
     * Cross-reference vhost extprocessors with the lsphp binaries on disk
     * to derive the ExtApps sub-tab rows.
     *
     * @param  array<string, array<string, mixed>>  $vhconfs
     * @param  array<string, mixed> $lsphpVersions
     * @return list<array<string, mixed>>
     */
    private function buildExtAppUnits(array $vhconfs, array $lsphpVersions): array
    {
        $rows = [];
        $seen = [];
        foreach ($vhconfs as $vhost => $cfg) {
            foreach ($cfg['extprocessors'] as $ep) {
                $key = (string) ($ep['name'] ?? '').'|'.(string) ($ep['path'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $path = (string) ($ep['path'] ?? '');
                $version = null;
                if (preg_match('#/lsphp(\d)(\d+)/bin/lsphp#', $path, $m)) {
                    $version = $m[1].'.'.$m[2];
                }
                $rows[] = [
                    'name' => $ep['name'] ?? '?',
                    'type' => $ep['type'] ?? null,
                    'path' => $path !== '' ? $path : null,
                    'php_version' => $version,
                    'installed' => $version !== null && in_array($version, $lsphpVersions, true),
                    'vhost' => $vhost,
                ];
            }
        }

        return $rows;
    }

    /**
     * Cache hits per source file (one row per .rtreport file). OLS only
     * splits public/private hits at the server level in most builds, so
     * the table is short — typically one row per worker process.
     *
     * @param  array<string, array<string, float|int>>  $rtreport
     * @return list<array<string, mixed>>
     */
    private function buildCacheUnits(array $rtreport): array
    {
        $rows = [];
        foreach ($rtreport as $path => $stats) {
            $public = (int) ($stats['TOTAL_PUB_CACHE_HITS'] ?? 0);
            $private = (int) ($stats['TOTAL_PRIVATE_CACHE_HITS'] ?? 0);
            $static = (int) ($stats['TOTAL_STATIC_HITS'] ?? 0);
            $total = (int) ($stats['TOT_REQS'] ?? 0);
            $hitRate = $total > 0 ? round(100.0 * ($public + $private) / $total, 2) : 0.0;
            $rows[] = [
                'source' => basename($path),
                'public_hits' => $public,
                'private_hits' => $private,
                'static_hits' => $static,
                'total_requests' => $total,
                'hit_rate_pct' => $hitRate,
            ];
        }

        return $rows;
    }
}
