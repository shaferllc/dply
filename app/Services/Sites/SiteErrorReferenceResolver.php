<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;
use Dply\LogParser\LaravelLogParser;
use Dply\LogParser\WebserverErrorLogParser;
use Throwable;

/**
 * Resolves a per-request reference code (the `X-Dply-Ref` shown on the branded
 * 5xx page) back to the actual error.
 *
 * Correlation is two-step and runs as a single capped bash script over SSH:
 *   1. The code is found in this site's PHP-FPM access log (logged via the
 *      REQUEST_ID fastcgi param), which pins the exact request — method, URI,
 *      epoch, duration, status.
 *   2. That epoch is used to pull the matching window out of the app's
 *      error logs (Laravel, the pool PHP error log, the webserver error log).
 *      Because each log stamps time in its own timezone, we match on second
 *      prefixes computed in BOTH UTC and server-local time (± a couple seconds).
 *
 * Read-only; never throws — a failed connection or empty result returns a
 * structured "not found" so the caller can explain it to the operator.
 */
final class SiteErrorReferenceResolver
{
    /**
     * Hard cap so a lookup can never stream an unbounded log back, but high
     * enough to carry a complete multi-line stack trace (a Laravel exception
     * dump is ~90-100 frames) plus a few correlated entries.
     */
    private const TRACE_LINE_CAP = 2000;

    public function __construct(
        private readonly SshConnectionFactory $sshFactory,
        private readonly LaravelLogParser $laravelParser = new LaravelLogParser,
        private readonly WebserverErrorLogParser $webserverParser = new WebserverErrorLogParser,
    ) {}

    /**
     * @return array{found: bool, reference: string, request: ?string, occurred_at: ?string, trace: list<string>, entries: list<array<string, mixed>>, primary: ?array<string, mixed>, note: ?string}
     */
    public function resolve(Site $site, string $reference): array
    {
        $reference = trim($reference);
        $miss = fn (?string $note = null): array => [
            'found' => false,
            'reference' => $reference,
            'request' => null,
            'occurred_at' => null,
            'trace' => [],
            'entries' => [],
            'primary' => null,
            'note' => $note,
        ];

        // The reference is nginx's $request_id (hex) / Caddy uuid — bound the
        // shape so we never shell out an arbitrary string.
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $reference)) {
            return $miss(__('That does not look like a valid reference code.'));
        }

        $site->loadMissing('server');
        $server = $site->server;

        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            return $miss(__('This site is not on an SSH-managed server, so its logs cannot be searched.'));
        }

        try {
            $ssh = $this->sshFactory->forServer($server);
            $raw = (string) $ssh->exec($this->script($site, $reference), 45);
        } catch (Throwable) {
            return $miss(__('Could not reach the server to search its logs.'));
        }

        return $this->parse($raw, $reference, $miss, $this->sourceMap($site));
    }

    /**
     * Map each remote log path the lookup script greps to the parser family that
     * understands it, so a trace section can be parsed with the right grammar.
     *
     * @return array<string, 'laravel'|'fpm'|'web'>
     */
    private function sourceMap(Site $site): array
    {
        return [
            $site->laravelLogPath() => 'laravel',
            $site->phpFpmPoolErrorLogPath() => 'fpm',
            $site->webserverErrorLogPath() => 'web',
        ];
    }

    /**
     * @param  callable(?string): array<string, mixed>  $miss
     * @param  array<string, 'laravel'|'fpm'|'web'>  $sourceMap
     * @return array{found: bool, reference: string, request: ?string, occurred_at: ?string, trace: list<string>, entries: list<array<string, mixed>>, primary: ?array<string, mixed>, note: ?string}
     */
    private function parse(string $raw, string $reference, callable $miss, array $sourceMap = []): array
    {
        $request = null;
        $occurredAt = null;
        $trace = [];
        $inTrace = false;

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (str_starts_with($line, 'DPLY_REQUEST:')) {
                $request = trim(substr($line, strlen('DPLY_REQUEST:')));

                continue;
            }
            if (str_starts_with($line, 'DPLY_AT:')) {
                $occurredAt = trim(substr($line, strlen('DPLY_AT:')));

                continue;
            }
            if (trim($line) === 'DPLY_TRACE_BEGIN') {
                $inTrace = true;

                continue;
            }
            if ($inTrace && trim($line) !== '') {
                $trace[] = rtrim($line);
            }
        }

        if ($request === null) {
            return $miss(__('No request with that reference was found in the recent logs. The code may be older than log retention, or the request never reached PHP (for example, the app was down and the webserver returned the error itself).'));
        }

        $entries = $this->structureTrace($trace, $sourceMap);

        return [
            'found' => true,
            'reference' => $reference,
            'request' => $request,
            'occurred_at' => $occurredAt,
            'trace' => array_slice($trace, 0, self::TRACE_LINE_CAP),
            'entries' => $entries,
            'primary' => $this->pickPrimary($entries),
            'note' => $trace === [] ? __('The request was found, but no matching error line was located in the app logs around that time.') : null,
        ];
    }

    /**
     * Turn the raw, mixed-source trace lines into structured log entries. The
     * lookup script delimits each log file with a `── /path ──` header; we use
     * the path to pick the right parser grammar (Laravel/Monolog for the app and
     * FPM logs, nginx/apache for the webserver error log). Lines that don't parse
     * are simply dropped from `entries` — they remain verbatim in `trace`.
     *
     * @param  list<string>  $trace
     * @param  array<string, 'laravel'|'fpm'|'web'>  $sourceMap
     * @return list<array<string, mixed>>
     */
    private function structureTrace(array $trace, array $sourceMap): array
    {
        $entries = [];
        $file = null;
        $source = null;
        $buffer = [];

        $flush = function () use (&$entries, &$buffer, &$file, &$source): void {
            $lines = $buffer;
            $buffer = [];
            if ($source === null || $lines === []) {
                return;
            }

            $text = implode("\n", $lines);
            $records = $source === 'web'
                ? $this->webserverParser->parse($text)
                : $this->laravelParser->parse($text);

            foreach ($records as $record) {
                if (($record['parsed'] ?? false) !== true) {
                    continue;
                }
                $entries[] = $this->normalizeEntry($record, $source, $file);
            }
        };

        foreach ($trace as $line) {
            if (preg_match('/^──\s*(.+?)\s*──$/', trim($line), $m)) {
                $flush();
                $file = $m[1];
                $source = $sourceMap[$file] ?? 'laravel';

                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return array_slice($entries, 0, self::TRACE_LINE_CAP);
    }

    /**
     * Flatten a parser record (Laravel or webserver) into a uniform entry shape.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeEntry(array $record, string $source, ?string $file): array
    {
        $datetime = $record['datetime'] ?? null;
        $level = (string) ($record['level'] ?? '');

        return [
            'source' => $source,
            'file' => $file,
            'level' => $level !== '' ? strtoupper($level) : null,
            'datetime' => $datetime instanceof \DateTimeInterface ? $datetime->format(DATE_ATOM) : null,
            'message' => trim((string) ($record['message'] ?? '')),
            'trace' => array_values(array_map('strval', (array) ($record['trace'] ?? []))),
            'raw' => (string) ($record['raw'] ?? ''),
        ];
    }

    /**
     * Pick the single most actionable entry to surface as the headline error:
     * the highest-severity line, breaking ties toward the application (Laravel)
     * log since that's where the stack trace and root cause live.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return ?array<string, mixed>
     */
    private function pickPrimary(array $entries): ?array
    {
        $rank = [
            'EMERGENCY' => 7, 'EMERG' => 7, 'ALERT' => 6, 'CRITICAL' => 5, 'CRIT' => 5,
            'ERROR' => 4, 'WARNING' => 3, 'WARN' => 3, 'NOTICE' => 2, 'INFO' => 1, 'DEBUG' => 0,
        ];

        $best = null;
        $bestScore = -1;
        foreach ($entries as $entry) {
            $severity = $rank[strtoupper((string) ($entry['level'] ?? ''))] ?? 1;
            // Weight severity above source, then prefer the app log on a tie.
            $score = ($severity * 2) + (($entry['source'] ?? '') === 'laravel' ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return $best;
    }

    /**
     * One self-contained, defensive bash script. Every read is guarded so a
     * missing log file degrades to "no match" rather than a non-zero exit.
     */
    private function script(Site $site, string $reference): string
    {
        $ref = escapeshellarg($reference);
        $fpmAccess = escapeshellarg($site->phpFpmAccessLogPath());
        $fpmError = escapeshellarg($site->phpFpmPoolErrorLogPath());
        $laravel = escapeshellarg($site->laravelLogPath());
        $webError = escapeshellarg($site->webserverErrorLogPath());
        $cap = self::TRACE_LINE_CAP;

        return <<<BASH
set +e
REF={$ref}

# 1) Find the request in the FPM access log (current + one rotation).
LINE=""
for f in {$fpmAccess} {$fpmAccess}.1; do
  [ -f "\$f" ] || continue
  M="\$(grep -F "ref=\${REF} " "\$f" 2>/dev/null | tail -n 1)"
  [ -n "\$M" ] && LINE="\$M"
done

if [ -z "\$LINE" ]; then
  echo "DPLY_NONE"
  exit 0
fi

EPOCH="\$(printf '%s' "\$LINE" | sed -n 's/.* t=\\([0-9][0-9]*\\).*/\\1/p')"
REQ="\$(printf '%s' "\$LINE" | sed -n 's/.* at=[^ ]* \\(.*\\) dur=.*/\\1/p')"
AT="\$(printf '%s' "\$LINE" | sed -n 's/.* at=\\([^ ]*\\).*/\\1/p')"
echo "DPLY_REQUEST:\${REQ}"
echo "DPLY_AT:\${AT}"

# 2) Build second-precision time prefixes around the request in BOTH utc and
#    server-local time (logs differ in tz), ±2s, to grep the error logs.
PATTERNS=""
if [ -n "\$EPOCH" ]; then
  for tz in -u ''; do
    for off in -2 -1 0 1 2; do
      P="\$(date \$tz -d @\$((EPOCH+off)) +'%Y-%m-%d %H:%M:%S' 2>/dev/null)"
      [ -n "\$P" ] && PATTERNS="\${PATTERNS}\n\${P}"
    done
  done
fi

echo "DPLY_TRACE_BEGIN"
if [ -n "\$PATTERNS" ]; then
  GREPF="\$(mktemp)"
  printf '%b' "\$PATTERNS" | sort -u | grep -v '^$' > "\$GREPF"
  for f in {$laravel} {$fpmError} {$webError}; do
    [ -f "\$f" ] || continue
    # A Laravel log entry is multi-line: a "[YYYY-MM-DD HH:MM:SS] …" header
    # followed by the message and the full "#0 … #N {main}" stack trace, none
    # of which carry their own timestamp. Grepping by timestamp alone would
    # capture only the header, so walk the file with awk: when a header line
    # matches one of our time patterns, print the WHOLE entry (header through
    # the line before the next "[YYYY-…" header), capped at {$cap} lines total.
    H="\$(tail -n 8000 "\$f" 2>/dev/null | awk -v capn={$cap} '
      BEGIN { while ((getline p < "'"\$GREPF"'") > 0) if (p != "") want[p]=1 }
      /^\\[[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/ {
        inb=0
        for (p in want) { if (index(\$0, p)) { inb=1; break } }
      }
      inb { print; c++; if (c >= capn) exit }
    ')"
    if [ -n "\$H" ]; then
      echo "── \$f ──"
      printf '%s\\n' "\$H"
    fi
  done
  rm -f "\$GREPF"
fi
exit 0
BASH;
    }
}
