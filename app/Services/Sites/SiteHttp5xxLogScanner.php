<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tier-2 of the server-error-reference feature: greps a site's PHP-FPM access
 * log for 5xx responses so application 500s become {@see \App\Models\ErrorEvent}
 * rows on their own (the Tier-1 {@see SiteErrorReferenceResolver} only resolves a
 * single reference the operator pastes in by hand).
 *
 * The pool access log carries one self-describing line per request — its format
 * is fixed by {@see SitePhpFpmPoolConfigBuilder}:
 *
 *   ref=<id> t=<epoch> at=<iso8601> <method> <uri> dur=<ms>ms status=<code>
 *
 * so a single capped, read-only bash script can pull recent 5xx lines without
 * any app-side log parsing on the box. Never throws — a missing log / dead
 * connection yields an empty result the caller treats as "nothing to capture".
 */
final class SiteHttp5xxLogScanner
{
    public function __construct(
        private readonly SshConnectionFactory $sshFactory,
    ) {}

    /**
     * @return array{ok: bool, hits: list<array{reference: string, status: int, method: string, uri: string, occurred_at: CarbonImmutable}>, truncated: bool, note: ?string}
     */
    public function scan(Site $site, int $lookbackMinutes, int $max): array
    {
        $miss = fn (?string $note = null): array => [
            'ok' => false, 'hits' => [], 'truncated' => false, 'note' => $note,
        ];

        $site->loadMissing('server');
        $server = $site->server;

        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            return $miss('Site is not on an SSH-managed server.');
        }
        if (! $site->usesDedicatedPhpFpmPool()) {
            return $miss('Site has no managed PHP-FPM pool to scan.');
        }

        $cutoff = now()->subMinutes(max(1, $lookbackMinutes))->getTimestamp();
        $max = max(1, $max);

        try {
            $ssh = $this->sshFactory->forServer($server);
            $raw = (string) $ssh->exec($this->script($site, $cutoff, $max), 45);
        } catch (Throwable) {
            return $miss('Could not reach the server to read its access log.');
        }

        return $this->parse($raw, $cutoff, $max);
    }

    /**
     * @return array{ok: bool, hits: list<array{reference: string, status: int, method: string, uri: string, occurred_at: CarbonImmutable}>, truncated: bool, note: ?string}
     */
    private function parse(string $raw, int $cutoff, int $max): array
    {
        $hits = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, 'ref=')) {
                continue;
            }
            // ref=<id> t=<epoch> at=<iso> <method> <uri...> dur=<ms>ms status=<code>
            if (! preg_match('/^ref=(\S+)\s+t=(\d+)\s+at=(\S+)\s+(\S+)\s+(.*?)\s+dur=\S*\s+status=(\d{3})$/', $line, $m)) {
                continue;
            }
            $epoch = (int) $m[2];
            $status = (int) $m[6];
            if ($epoch < $cutoff || $status < 500 || $status > 599) {
                continue;
            }
            $reference = $m[1];
            // Defensive: references are nginx $request_id (hex) / Caddy uuid.
            if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $reference)) {
                continue;
            }

            $hits[] = [
                'reference' => $reference,
                'status' => $status,
                'method' => mb_substr($m[4], 0, 10),
                'uri' => mb_substr($m[5], 0, 500),
                'occurred_at' => CarbonImmutable::createFromTimestamp($epoch),
            ];
        }

        // Newest first, then cap. The script already tails, but a window with
        // more than $max 5xx lines is truncated here too — flag it for the caller.
        usort($hits, fn ($a, $b) => $b['occurred_at']->getTimestamp() <=> $a['occurred_at']->getTimestamp());
        $truncated = count($hits) > $max;

        return [
            'ok' => true,
            'hits' => array_slice($hits, 0, $max),
            'truncated' => $truncated,
            'note' => null,
        ];
    }

    /**
     * Read-only, self-contained. Reads only the tail of the access log (current +
     * one rotation), filters to 5xx lines, and hands back at most $max of the most
     * recent. Every step degrades to "no output" rather than a non-zero exit.
     *
     * PHP-FPM runs as root and opens its per-pool access log `root:root 0600`, so
     * the operational SSH user cannot read it directly — without the sudo attempt
     * every 5xx silently vanishes and the Errors stream stays empty. We try
     * passwordless `sudo` first, then fall back to a plain read (file already
     * readable, or no sudo on the box). Both paths suppress errors, so the script
     * still degrades to "no output".
     */
    private function script(Site $site, int $cutoff, int $max): string
    {
        $access = escapeshellarg($site->phpFpmAccessLogPath());
        // Bound the raw read so a giant log can't be streamed back; 5xx is rare
        // relative to total traffic, so the tail comfortably covers the window.
        $rawTail = 60000;

        return <<<BASH
set +e
for f in {$access} {$access}.1; do
  [ -f "\$f" ] || continue
  { sudo -n tail -n {$rawTail} "\$f" 2>/dev/null || tail -n {$rawTail} "\$f" 2>/dev/null; }
done | grep -E ' status=5[0-9][0-9]\$' 2>/dev/null | tail -n {$max}
exit 0
BASH;
    }
}
