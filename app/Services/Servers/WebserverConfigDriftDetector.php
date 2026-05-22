<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Compares each Site's on-disk webserver config against what dply's
 * per-site builder would emit RIGHT NOW. Surfaces a per-site "drift
 * count" so operators can answer "if I run Apply on this server, what
 * actually changes?" without speccing it out by hand.
 *
 * Why this matters: dply's UI lets operators tweak configs directly
 * (cache modules, vhost editors, the file editor on the Config tab),
 * but the per-site provisioner regenerates these files on every Site
 * Apply / webserver switch. Drift here means edits that'll get
 * clobbered on the next provisioner pass.
 *
 * Detection only — surfacing what's different. No remediation;
 * operators run the existing Apply flow if they want to make the
 * on-disk file match dply's expected content.
 *
 * Cached per (server, engine) for 60s — operators rescan via the
 * "Recheck" button when they want a fresh diff.
 */
class WebserverConfigDriftDetector
{
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Max sites compared per run. Bounded so a server with 500 sites
     * doesn't burn PHP's request timeout on the diff loop.
     */
    private const MAX_SITES_PER_RUN = 60;

    /**
     * Soft cap on the unified-diff payload per file — most operators
     * want a quick glance, not a 5,000-line dump. The full diff stays
     * available via the existing Config sub-tab + revisions panel.
     */
    private const DIFF_MAX_LINES = 60;

    /**
     * @return array{
     *     engine: ?string,
     *     results: list<array{site_id: string, site_name: string, path: string, drifted: bool, added: int, removed: int, diff: string, error: ?string}>,
     *     total_sites: int,
     *     drifted_count: int,
     *     scanned_at: \Carbon\CarbonImmutable,
     *     truncated: bool,
     *     unsupported: bool,
     * }
     */
    public function detect(Server $server, bool $forceFresh = false): array
    {
        $cacheKey = 'dply.webserver-drift:'.$server->id;
        if (! $forceFresh) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->rehydrate($cached);
            }
        }

        $engine = strtolower(trim((string) data_get($server->meta ?? [], 'webserver', '')));
        if ($engine === '' || ! in_array($engine, ['openlitespeed', 'caddy', 'nginx', 'apache'], true)) {
            return $this->emptyResult($engine ?: null, true);
        }

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->where('status', '!=', 'deleted')
            ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'webserverConfigProfile', 'certificates', 'server'])
            ->orderBy('name')
            ->get();

        $total = $sites->count();
        $candidates = $sites->take(self::MAX_SITES_PER_RUN);
        $truncated = $total > self::MAX_SITES_PER_RUN;

        if ($candidates->isEmpty()) {
            $payload = [
                'engine' => $engine,
                'results' => [],
                'total_sites' => 0,
                'drifted_count' => 0,
                'scanned_at' => CarbonImmutable::now(),
                'truncated' => false,
                'unsupported' => false,
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

            return $payload;
        }

        // Build expected content for each site upfront — this is pure
        // local computation (no SSH), bounded by the candidates cap.
        $expected = [];
        $paths = [];
        foreach ($candidates as $site) {
            $path = $this->pathFor($engine, $site);
            if ($path === null) {
                continue;
            }
            try {
                $expected[$site->id] = $this->expectedContentFor($engine, $site);
                $paths[$site->id] = $path;
            } catch (\Throwable $e) {
                $expected[$site->id] = null;
                $paths[$site->id] = $path;
            }
        }

        // One SSH round-trip pulls every path's on-disk content using a
        // base64-encoded delimiter scheme so binary-clean and we can
        // split per-file without quoting headaches.
        $onDisk = $this->fetchOnDiskContents($server, array_values($paths));

        $results = [];
        $driftedCount = 0;
        foreach ($candidates as $site) {
            $path = $paths[$site->id] ?? null;
            if ($path === null) {
                continue;
            }
            $expectedText = $expected[$site->id] ?? null;
            $actualText = $onDisk[$path] ?? '';

            if ($expectedText === null) {
                $results[] = [
                    'site_id' => (string) $site->id,
                    'site_name' => (string) $site->name,
                    'path' => $path,
                    'drifted' => false,
                    'added' => 0,
                    'removed' => 0,
                    'diff' => '',
                    'error' => 'builder failed',
                ];

                continue;
            }

            $diffStats = $this->computeDiff($actualText, $expectedText);
            $isDrifted = $diffStats['added'] > 0 || $diffStats['removed'] > 0;
            if ($isDrifted) {
                $driftedCount++;
            }

            $results[] = [
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'path' => $path,
                'drifted' => $isDrifted,
                'added' => $diffStats['added'],
                'removed' => $diffStats['removed'],
                'diff' => $diffStats['diff'],
                'error' => null,
            ];
        }

        // Drifted entries float to the top so the operator sees the
        // problems first.
        usort($results, function (array $a, array $b): int {
            $aSort = $a['drifted'] ? 0 : ($a['error'] !== null ? 1 : 2);
            $bSort = $b['drifted'] ? 0 : ($b['error'] !== null ? 1 : 2);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }
            // Within the same bucket, sort by total change size descending.
            return ($b['added'] + $b['removed']) <=> ($a['added'] + $a['removed']);
        });

        $payload = [
            'engine' => $engine,
            'results' => $results,
            'total_sites' => $total,
            'drifted_count' => $driftedCount,
            'scanned_at' => CarbonImmutable::now(),
            'truncated' => $truncated,
            'unsupported' => false,
        ];
        \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * Per-site config path for a given engine. Mirrors the path each
     * engine's per-site provisioner writes to.
     */
    private function pathFor(string $engine, Site $site): ?string
    {
        if (! method_exists($site, 'webserverConfigBasename')) {
            return null;
        }
        $basename = (string) $site->webserverConfigBasename();
        if ($basename === '') {
            return null;
        }

        return match ($engine) {
            'openlitespeed' => '/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf',
            'caddy' => '/etc/caddy/sites-enabled/'.$basename.'.caddy',
            'nginx' => '/etc/nginx/sites-enabled/'.$basename,
            'apache' => '/etc/apache2/sites-enabled/'.$basename.'.conf',
            default => null,
        };
    }

    /**
     * Dispatch to the right per-site builder for the engine.
     */
    private function expectedContentFor(string $engine, Site $site): string
    {
        return match ($engine) {
            'openlitespeed' => app(OpenLiteSpeedSiteConfigBuilder::class)->build($site),
            'caddy' => app(CaddySiteConfigBuilder::class)->build($site),
            'nginx' => app(NginxSiteConfigBuilder::class)->build($site, $site->webserverConfigProfile),
            'apache' => app(ApacheSiteConfigBuilder::class)->build($site),
            default => '',
        };
    }

    /**
     * Pull on-disk content for every supplied path in one SSH call. Uses
     * a marker scheme so we can split per-file from the combined output.
     *
     * @param  list<string>  $paths
     * @return array<string, string>  path → contents
     */
    private function fetchOnDiskContents(Server $server, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $pathArgs = implode(' ', array_map('escapeshellarg', $paths));
        $script = <<<BASH
set +e
for f in {$pathArgs}; do
    printf '###dply-file-start:%s###\\n' "\$f"
    sudo -n cat "\$f" 2>/dev/null
    printf '\\n###dply-file-end###\\n'
done
BASH;

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($script, 20);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        $offset = 0;
        $len = strlen($output);
        while ($offset < $len) {
            $startTag = strpos($output, '###dply-file-start:', $offset);
            if ($startTag === false) {
                break;
            }
            $pathEnd = strpos($output, '###', $startTag + strlen('###dply-file-start:'));
            if ($pathEnd === false) {
                break;
            }
            $path = substr($output, $startTag + strlen('###dply-file-start:'), $pathEnd - ($startTag + strlen('###dply-file-start:')));
            $contentStart = strpos($output, "\n", $pathEnd);
            if ($contentStart === false) {
                break;
            }
            $contentStart++;
            $contentEnd = strpos($output, '###dply-file-end###', $contentStart);
            if ($contentEnd === false) {
                $contentEnd = $len;
            }
            $content = substr($output, $contentStart, $contentEnd - $contentStart);
            // Strip the trailing newline our printf adds before the end marker.
            $content = (string) preg_replace('/\n$/', '', $content);
            $out[$path] = $content;
            $offset = $contentEnd + strlen('###dply-file-end###');
        }

        return $out;
    }

    /**
     * Line-by-line diff. Returns counts + a truncated unified-diff
     * preview. Trades perfect minimum-distance accuracy for predictable
     * O(n) behaviour — we just need a useful operator-facing summary.
     *
     * @return array{added: int, removed: int, diff: string}
     */
    private function computeDiff(string $actual, string $expected): array
    {
        $actualLines = preg_split('/\R/', $actual) ?: [];
        $expectedLines = preg_split('/\R/', $expected) ?: [];

        // Trim trailing blank line if either side has one (cosmetic
        // difference that's not worth flagging as drift).
        if ($actualLines !== [] && end($actualLines) === '') {
            array_pop($actualLines);
        }
        if ($expectedLines !== [] && end($expectedLines) === '') {
            array_pop($expectedLines);
        }

        // Compute the longest common subsequence the lazy way: walk both
        // sides in parallel, treating matching lines as "kept" and
        // diverging runs as add/remove. Good enough for the
        // mostly-aligned case (typical dply-managed files where the
        // operator tweaked a handful of directives).
        $aIdx = 0;
        $eIdx = 0;
        $aCount = count($actualLines);
        $eCount = count($expectedLines);
        $added = 0;
        $removed = 0;
        $diffLines = [];
        $included = 0;

        while ($aIdx < $aCount || $eIdx < $eCount) {
            $aLine = $aIdx < $aCount ? $actualLines[$aIdx] : null;
            $eLine = $eIdx < $eCount ? $expectedLines[$eIdx] : null;

            if ($aLine !== null && $aLine === $eLine) {
                $aIdx++;
                $eIdx++;

                continue;
            }

            // Lookahead: see if the actual line shows up soon in expected
            // (= insertion in expected, log as +). Or vice versa.
            $forwardLookExpected = $aLine !== null ? array_search($aLine, array_slice($expectedLines, $eIdx, 5), true) : false;
            $forwardLookActual = $eLine !== null ? array_search($eLine, array_slice($actualLines, $aIdx, 5), true) : false;

            if ($forwardLookExpected !== false && ($forwardLookActual === false || $forwardLookExpected <= $forwardLookActual)) {
                // Expected has extra lines before the next matching line.
                for ($i = 0; $i < $forwardLookExpected; $i++) {
                    $added++;
                    if ($included < self::DIFF_MAX_LINES) {
                        $diffLines[] = '+ '.$expectedLines[$eIdx + $i];
                        $included++;
                    }
                }
                $eIdx += $forwardLookExpected;

                continue;
            }

            if ($forwardLookActual !== false) {
                // Actual has extra lines.
                for ($i = 0; $i < $forwardLookActual; $i++) {
                    $removed++;
                    if ($included < self::DIFF_MAX_LINES) {
                        $diffLines[] = '- '.$actualLines[$aIdx + $i];
                        $included++;
                    }
                }
                $aIdx += $forwardLookActual;

                continue;
            }

            // Neither side's current line matches in the near future →
            // treat as a 1-for-1 substitution.
            if ($aLine !== null) {
                $removed++;
                if ($included < self::DIFF_MAX_LINES) {
                    $diffLines[] = '- '.$aLine;
                    $included++;
                }
                $aIdx++;
            }
            if ($eLine !== null) {
                $added++;
                if ($included < self::DIFF_MAX_LINES) {
                    $diffLines[] = '+ '.$eLine;
                    $included++;
                }
                $eIdx++;
            }
        }

        if (($added + $removed) > self::DIFF_MAX_LINES && $included >= self::DIFF_MAX_LINES) {
            $diffLines[] = sprintf('… (%d more line(s) elided)', ($added + $removed) - $included);
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'diff' => implode("\n", $diffLines),
        ];
    }

    private function emptyResult(?string $engine, bool $unsupported): array
    {
        return [
            'engine' => $engine,
            'results' => [],
            'total_sites' => 0,
            'drifted_count' => 0,
            'scanned_at' => CarbonImmutable::now(),
            'truncated' => false,
            'unsupported' => $unsupported,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function rehydrate(array $cached): array
    {
        $scannedAt = $cached['scanned_at'] ?? null;
        if (is_string($scannedAt)) {
            try {
                $scannedAt = CarbonImmutable::parse($scannedAt);
            } catch (\Throwable) {
                $scannedAt = CarbonImmutable::now();
            }
        }

        return [
            'engine' => $cached['engine'] ?? null,
            'results' => is_array($cached['results'] ?? null) ? array_values($cached['results']) : [],
            'total_sites' => (int) ($cached['total_sites'] ?? 0),
            'drifted_count' => (int) ($cached['drifted_count'] ?? 0),
            'scanned_at' => $scannedAt instanceof CarbonImmutable ? $scannedAt : CarbonImmutable::now(),
            'truncated' => (bool) ($cached['truncated'] ?? false),
            'unsupported' => (bool) ($cached['unsupported'] ?? false),
        ];
    }
}
