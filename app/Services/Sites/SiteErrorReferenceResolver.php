<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;
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
    /** Hard caps so a lookup can never stream an unbounded log back. */
    private const TRACE_LINE_CAP = 150;

    public function __construct(
        private readonly SshConnectionFactory $sshFactory,
    ) {}

    /**
     * @return array{found: bool, reference: string, request: ?string, occurred_at: ?string, trace: list<string>, note: ?string}
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

        return $this->parse($raw, $reference, $miss);
    }

    /**
     * @param  callable(?string): array{found: bool, reference: string, request: ?string, occurred_at: ?string, trace: list<string>, note: ?string}  $miss
     * @return array{found: bool, reference: string, request: ?string, occurred_at: ?string, trace: list<string>, note: ?string}
     */
    private function parse(string $raw, string $reference, callable $miss): array
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

        return [
            'found' => true,
            'reference' => $reference,
            'request' => $request,
            'occurred_at' => $occurredAt,
            'trace' => array_slice($trace, 0, self::TRACE_LINE_CAP),
            'note' => $trace === [] ? __('The request was found, but no matching error line was located in the app logs around that time.') : null,
        ];
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
    H="\$(tail -n 4000 "\$f" 2>/dev/null | grep -F -f "\$GREPF" 2>/dev/null | head -n {$cap})"
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
