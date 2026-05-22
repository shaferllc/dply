<?php

declare(strict_types=1);

namespace App\Services\Servers;

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

    /**
     * @return array{certs: list<array{path: string, subject: string, issuer: string, not_after: ?string, expires_at: ?CarbonImmutable, days_until_expiry: ?int, urgency: string, engine_hint: string, error: ?string}>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    public function aggregate(Server $server, bool $forceFresh = false): array
    {
        $cacheKey = 'dply.webserver-certs:'.$server->id;

        if (! $forceFresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->rehydrate($cached);
            }
        }

        $payload = $this->runScan($server);
        Cache::put($cacheKey, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * @return array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}
     */
    private function runScan(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($this->buildScanScript(), 20);
            $exit = $ssh->lastExecExitCode();
        } catch (\Throwable) {
            return ['certs' => [], 'scanned_at' => null, 'unreadable' => true];
        }
        if ($exit !== null && $exit !== 0) {
            return ['certs' => [], 'scanned_at' => null, 'unreadable' => true];
        }

        $certs = $this->parseScanOutput((string) $output);

        usort($certs, function (array $a, array $b): int {
            $aDays = $a['days_until_expiry'] ?? PHP_INT_MAX;
            $bDays = $b['days_until_expiry'] ?? PHP_INT_MAX;

            return $aDays <=> $bDays;
        });

        return [
            'certs' => $certs,
            'scanned_at' => CarbonImmutable::now(),
            'unreadable' => false,
        ];
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
    private function buildScanScript(): string
    {
        $paths = implode(' ', array_map('escapeshellarg', self::SEARCH_PATHS));

        // Filter rules at the awk stage: skip privkey-style files (which
        // openssl x509 would refuse) and OS CA bundle paths (the goal is
        // the server's own server-certs, not the trust store).
        return <<<BASH
set +e
sudo -n find {$paths} -type f -maxdepth 5 \\
    \\( -name '*.pem' -o -name '*.crt' -o -name '*.cer' -o -name 'fullchain.pem' -o -name 'cert.pem' \\) \\
    2>/dev/null \\
    | grep -Ev '/(privkey|key|cabundle|ca-bundle|ca-certificates|chain)\\.pem$' \\
    | sort -u \\
    | while IFS= read -r f; do
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
