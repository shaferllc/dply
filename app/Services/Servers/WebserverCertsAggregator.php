<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ScanServerLiveCertsJob;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-engine TLS certificates aggregator. Sweeps the canonical cert
 * paths across all dply-supported webservers + edge proxies in one
 * SSH round-trip and returns a flat list of (path, subject, issuer,
 * expiry, urgency) tuples for the Overview dashboard card.
 *
 * Per-engine live-state probes already surface certs in narrow contexts
 * (nginx ssl_certificate paths, Caddy TLS policies, etc.). This service
 * is the wide complement — one list across everything on the box,
 * sorted by expiry-soonest-first so operators can answer "what's about
 * to break my TLS" without clicking between engines.
 *
 * Heuristics for filtering out CA bundles + system certs are baked in
 * — the goal is the operator's server-cert inventory, not the OS-level
 * /etc/ssl/certs trust store.
 */
class WebserverCertsAggregator
{
    /**
     * Per-server cache TTL (seconds). Cert expiry doesn't change on a
     * sub-minute cadence; an Overview card poll every 60s is fine
     * served from cache.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Days-until-expiry thresholds for the urgency pill.
     */
    private const URGENCY_DANGER_DAYS = 14;

    private const URGENCY_WARN_DAYS = 60;

    /** TTL + cap for the per-server live-progress log the scanning UI polls. */
    private const PROGRESS_TTL_SECONDS = 180;

    private const PROGRESS_MAX_LINES = 80;

    /**
     * Where to look. Order is preserved in the result so dply-managed
     * paths sort above ad-hoc operator drops under /etc/ssl/certs.
     *
     * @var list<string>
     */
    private const SEARCH_PATHS = [
        '/etc/letsencrypt/live',
        '/var/lib/caddy/.local/share/caddy/certificates',
        '/etc/haproxy/certs',
        '/etc/nginx/ssl',
        '/etc/nginx/certs',
        '/etc/apache2/ssl',
        '/etc/apache2/certs',
        '/etc/ssl/dply',
    ];

    public static function cacheKey(string $serverId): string
    {
        return 'dply.webserver-certs:'.$serverId;
    }

    /** Marks that a scan job is queued/running for a server, so we don't pile up dispatches. */
    public static function inflightKey(string $serverId): string
    {
        return 'dply.webserver-certs:inflight:'.$serverId;
    }

    /** Holds the live, append-only progress log the scanning UI polls and renders. */
    public static function progressKey(string $serverId): string
    {
        return 'dply.webserver-certs:progress:'.$serverId;
    }

    /**
     * The live-progress lines for the in-flight (or most recent) scan, oldest
     * first. The scan job appends to this as it streams over SSH; the Livewire
     * surfaces poll it so the operator sees what the sweep is doing instead of a
     * bare spinner.
     *
     * @return list<array{t: int, line: string}>
     */
    public function progress(Server $server): array
    {
        $raw = Cache::get(self::progressKey((string) $server->id));

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /** Clear the progress log for a fresh scan, optionally seeding the first line. */
    public function resetProgress(string $serverId, ?string $seed = null): void
    {
        $lines = $seed !== null ? [['t' => $this->nowMs(), 'line' => $seed]] : [];
        Cache::put(self::progressKey($serverId), $lines, now()->addSeconds(self::PROGRESS_TTL_SECONDS));
    }

    /** Append one line to the live-progress log, capped to the most recent N. */
    public function pushProgress(string $serverId, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $raw = Cache::get(self::progressKey($serverId));
        $lines = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
        $lines[] = ['t' => $this->nowMs(), 'line' => mb_substr($line, 0, 300)];
        if (count($lines) > self::PROGRESS_MAX_LINES) {
            $lines = array_slice($lines, -self::PROGRESS_MAX_LINES);
        }

        Cache::put(self::progressKey($serverId), $lines, now()->addSeconds(self::PROGRESS_TTL_SECONDS));
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * The cached sweep for a server, or null when nothing is cached yet (no SSH).
     * This is the read side the Livewire surfaces poll; the SSH work happens in
     * {@see ScanServerLiveCertsJob} via {@see scanAndCache()}.
     *
     * @return array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}|null
     */
    public function cached(Server $server): ?array
    {
        $cached = Cache::get(self::cacheKey((string) $server->id));

        return is_array($cached) ? $this->rehydrate($cached) : null;
    }

    /**
     * Queue an async SSH sweep when there's no fresh cache (or one is forced),
     * deduped so concurrent viewers don't stack jobs. Callers then poll
     * {@see cached()} for the result — SSH never runs in the request.
     */
    public function dispatchScan(Server $server, bool $forceFresh = false): void
    {
        $serverId = (string) $server->id;

        if ($forceFresh) {
            // Drop the cache so cached() reports "no result yet" and the UI shows
            // the scanning state until the fresh job repopulates it.
            Cache::forget(self::cacheKey($serverId));
        }

        if (Cache::get(self::inflightKey($serverId))) {
            return;
        }

        Cache::put(self::inflightKey($serverId), true, now()->addSeconds(180));
        $this->resetProgress($serverId, 'Scan queued — waiting for a worker to pick it up…');
        ScanServerLiveCertsJob::dispatch($serverId);
    }

    /**
     * Run the SSH sweep and persist it to cache. Called from the queue job, never
     * inline from a request. Clears the in-flight marker on completion.
     *
     * @return array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    public function scanAndCache(Server $server): array
    {
        $payload = $this->runScan($server);
        Cache::put(self::cacheKey((string) $server->id), $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));
        Cache::forget(self::inflightKey((string) $server->id));

        return $payload;
    }

    /**
     * Cache an "unreadable" result and clear the in-flight marker. Used when the
     * scan job fails outright so a polling UI resolves to the SSH-error state
     * instead of spinning on "scanning" forever.
     */
    public function cacheUnreadable(string $serverId): void
    {
        Cache::put(
            self::cacheKey($serverId),
            ['certs' => [], 'scanned_at' => null, 'unreadable' => true],
            now()->addSeconds(self::CACHE_TTL_SECONDS),
        );
        Cache::forget(self::inflightKey($serverId));
        $this->pushProgress($serverId, 'Scan failed — the worker could not complete it.');
    }

    /**
     * Synchronous cache-or-scan. Retained for non-request callers (and tests);
     * request/Livewire surfaces use {@see cached()} + {@see dispatchScan()} so
     * the 20s SSH probe never runs in the page request.
     *
     * @return array{certs: list<array{path: string, subject: string, issuer: string, not_after: ?string, expires_at: ?CarbonImmutable, days_until_expiry: ?int, urgency: string, engine_hint: string, error: ?string}>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    public function aggregate(Server $server, bool $forceFresh = false): array
    {
        if (! $forceFresh) {
            $cached = $this->cached($server);
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->scanAndCache($server);
    }

    /**
     * @return array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    private function runScan(Server $server): array
    {
        $serverId = (string) $server->id;

        // Find first (one fast round-trip), then read certs in chunks over
        // separate round-trips. Each chunk returns promptly and we push a
        // progress frame right after — so the live log fills second-by-second.
        // A single buffered sweep can't do this: a non-TTY pipe block-buffers
        // the whole (tiny) output and flushes it all at once at command exit,
        // which the UI then resolves to the result before any frame is seen.
        try {
            $ssh = new SshConnection($server);
            $this->pushProgress($serverId, 'Connected over SSH — searching certificate directories…');
            $findOut = $ssh->exec($this->buildFindScript(), 20);
            $findExit = $ssh->lastExecExitCode();
        } catch (\Throwable) {
            $this->pushProgress($serverId, 'SSH connection failed — could not run the scan.');

            return ['certs' => [], 'scanned_at' => null, 'unreadable' => true];
        }

        if ($findExit !== null && $findExit !== 0) {
            $this->pushProgress($serverId, 'Could not search certificate directories (sudo/find failed).');

            return ['certs' => [], 'scanned_at' => null, 'unreadable' => true];
        }

        $files = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $findOut) ?: []),
            fn (string $f): bool => $f !== '',
        ));
        $total = count($files);
        $this->pushProgress($serverId, sprintf('Found %d candidate certificate file(s).', $total));

        if ($total === 0) {
            $this->pushProgress($serverId, 'No certificates found under the scanned paths.');

            return ['certs' => [], 'scanned_at' => CarbonImmutable::now(), 'unreadable' => false];
        }

        // Cap frames/round-trips at ~24 so a big box doesn't fan out unbounded;
        // small boxes read one cert per round-trip for the nicest live feel.
        $chunkSize = max(1, (int) ceil($total / 24));
        $certs = [];
        $read = 0;
        foreach (array_chunk($files, $chunkSize) as $chunk) {
            try {
                $out = $ssh->exec($this->buildReadScript($chunk), 20);
            } catch (\Throwable) {
                $out = '';
            }
            $rows = $this->parseScanOutput($out);
            foreach ($rows as $row) {
                $certs[] = $row;
            }
            $read += count($chunk);
            $this->pushProgress($serverId, $this->frameLine($read, $total, $chunk, $rows));
        }

        usort($certs, function (array $a, array $b): int {
            $aDays = $a['days_until_expiry'] ?? PHP_INT_MAX;
            $bDays = $b['days_until_expiry'] ?? PHP_INT_MAX;

            return $aDays <=> $bDays;
        });

        $this->pushProgress($serverId, sprintf('Done — parsed %d certificate(s).', count($certs)));

        return [
            'certs' => $certs,
            'scanned_at' => CarbonImmutable::now(),
            'unreadable' => false,
        ];
    }

    /**
     * A "[read/total] name · Nd" progress frame naming the cert just read, with
     * its days-until-expiry when we got one — so each frame is informative.
     *
     * @param  list<string>  $chunk
     * @param  list<array<string, mixed>>  $rows
     */
    private function frameLine(int $read, int $total, array $chunk, array $rows): string
    {
        $last = end($chunk);
        $label = is_string($last) && $last !== '' ? basename($last) : '';

        $lastRow = end($rows);
        if (is_array($lastRow) && ($lastRow['days_until_expiry'] ?? null) !== null) {
            $label = trim($label.' · '.(int) $lastRow['days_until_expiry'].'d');
        }

        return trim(sprintf('[%d/%d] %s', min($read, $total), $total, $label));
    }

    /**
     * Why this shape: one bash heredoc that walks each search path with
     * find (max-depth 5 so a misconfigured Let's Encrypt tree doesn't
     * spiral), pipes through openssl x509 for each candidate, and emits
     * a pipe-separated `path|subject|issuer|notAfter` line. Failures per
     * cert (unreadable, not actually a cert, no subject) emit a blank
     * field so the line still parses.
     *
     * sudo -n so we can read /etc/letsencrypt/live/* (root:root 0700 on
     * stock Ubuntu) without prompting.
     */
    /**
     * Step 1: list candidate cert files only (one path per line). Filter rules
     * skip privkey-style files (which openssl x509 would refuse) and OS CA bundle
     * paths — the goal is the server's own server-certs, not the trust store.
     */
    private function buildFindScript(): string
    {
        $paths = implode(' ', array_map('escapeshellarg', self::SEARCH_PATHS));

        return <<<BASH
set +e
sudo -n find {$paths} -type f -maxdepth 5 \\
    \\( -name '*.pem' -o -name '*.crt' -o -name '*.cer' -o -name 'fullchain.pem' -o -name 'cert.pem' \\) \\
    2>/dev/null \\
    | grep -Ev '/(privkey|key|cabundle|ca-bundle|ca-certificates|chain)\\.pem\$' \\
    | sort -u
BASH;
    }

    /**
     * Step 2: openssl-read a specific batch of files, emitting one
     * `path|subject|issuer|notAfter` line each (the format {@see parseScanOutput}
     * consumes). Unreadable/non-cert files are skipped silently.
     *
     * @param  list<string>  $files
     */
    private function buildReadScript(array $files): string
    {
        $list = implode(' ', array_map('escapeshellarg', $files));

        return <<<BASH
set +e
for f in {$list}; do
    out=\$(sudo -n openssl x509 -in "\$f" -noout -subject -issuer -enddate 2>/dev/null)
    if [ -z "\$out" ]; then
        continue
    fi
    subj=\$(printf %s "\$out" | sed -n 's/^subject= *//p' | head -n 1)
    iss=\$(printf %s "\$out" | sed -n 's/^issuer= *//p' | head -n 1)
    exp=\$(printf %s "\$out" | sed -n 's/^notAfter= *//p' | head -n 1)
    printf '%s|%s|%s|%s\\n' "\$f" "\$subj" "\$iss" "\$exp"
done
BASH;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseScanOutput(string $output): array
    {
        $now = CarbonImmutable::now();
        $rows = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 4);
            if (count($parts) < 4) {
                continue;
            }
            [$path, $subject, $issuer, $notAfter] = $parts;
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $subject = trim($subject);
            $issuer = trim($issuer);
            $notAfter = trim($notAfter);

            $expiresAt = null;
            $daysUntilExpiry = null;
            $urgency = 'unknown';
            $error = null;
            if ($notAfter !== '') {
                try {
                    $expiresAt = CarbonImmutable::createFromFormat('M j H:i:s Y T', $notAfter)
                        ?: CarbonImmutable::parse($notAfter);
                    $daysUntilExpiry = (int) floor($now->diffInSeconds($expiresAt, false) / 86400);
                    $urgency = $this->urgencyFor($daysUntilExpiry);
                } catch (\Throwable $e) {
                    $error = 'unparseable notAfter: '.$notAfter;
                }
            } else {
                $error = 'no notAfter';
            }

            $rows[] = [
                'path' => $path,
                'subject' => $subject,
                'issuer' => $issuer,
                'not_after' => $notAfter !== '' ? $notAfter : null,
                'expires_at' => $expiresAt,
                'days_until_expiry' => $daysUntilExpiry,
                'urgency' => $urgency,
                'engine_hint' => $this->engineHint($path),
                'error' => $error,
            ];
        }

        return $rows;
    }

    private function urgencyFor(int $days): string
    {
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= self::URGENCY_DANGER_DAYS) {
            return 'danger';
        }
        if ($days <= self::URGENCY_WARN_DAYS) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Best-effort guess at which engine "owns" a cert based on the path.
     * Used as a hint in the dashboard so the operator can click through
     * to the right per-engine sub-tab.
     */
    private function engineHint(string $path): string
    {
        if (str_starts_with($path, '/var/lib/caddy/')) {
            return 'caddy';
        }
        if (str_starts_with($path, '/etc/haproxy/')) {
            return 'haproxy';
        }
        if (str_starts_with($path, '/etc/nginx/')) {
            return 'nginx';
        }
        if (str_starts_with($path, '/etc/apache2/')) {
            return 'apache';
        }
        if (str_starts_with($path, '/etc/letsencrypt/')) {
            return 'letsencrypt';
        }

        return 'other';
    }

    /**
     * Cache::get hands back the array as-is — including CarbonImmutable
     * instances which serialise fine through Redis. But to be safe across
     * cache backends that might JSON-encode, we re-create the carbon
     * objects from `not_after` on the way back out.
     *
     * @param  array<string, mixed>  $cached
     * @return array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    private function rehydrate(array $cached): array
    {
        $certs = is_array($cached['certs'] ?? null) ? $cached['certs'] : [];
        foreach ($certs as &$cert) {
            if (! is_array($cert)) {
                continue;
            }
            if (is_string($cert['expires_at'] ?? null)) {
                try {
                    $cert['expires_at'] = CarbonImmutable::parse($cert['expires_at']);
                } catch (\Throwable) {
                    $cert['expires_at'] = null;
                }
            }
        }
        unset($cert);

        $scannedAt = $cached['scanned_at'] ?? null;
        if (is_string($scannedAt)) {
            try {
                $scannedAt = CarbonImmutable::parse($scannedAt);
            } catch (\Throwable) {
                $scannedAt = null;
            }
        }

        return [
            'certs' => array_values($certs),
            'scanned_at' => $scannedAt instanceof CarbonImmutable ? $scannedAt : null,
            'unreadable' => (bool) ($cached['unreadable'] ?? false),
        ];
    }
}
