<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Detect end-of-life or near-EOL Node.js major versions installed on the
 * server. Probes `node -v` and any /usr/local/bin/node, /usr/bin/node,
 * or nvm-managed binaries; compares the major against the bundled
 * config/insights_nodejs_eol.php schedule.
 *
 * Severity:
 *   - critical: EOL date has passed
 *   - warning : EOL within `warn_days` (default 90)
 *   - silent  : everything else
 *
 * Sites set their own runtime_version per-app (when applicable), but the
 * detector here is server-scoped so a server running multiple sites on the
 * same Node major only emits one finding.
 */
class NodejsUpdatesInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            return [];
        }

        $script = <<<'BASH'
# Scrape all locally-resolvable node binaries (system + common nvm/asdf
# locations) and emit one "version=X.Y.Z path=..." line per binary.
shopt -s nullglob
emitted=0
for candidate in /usr/bin/node /usr/local/bin/node /opt/node*/bin/node /root/.nvm/versions/node/*/bin/node /home/*/.nvm/versions/node/*/bin/node; do
  if [ -x "$candidate" ]; then
    ver=$("$candidate" -v 2>/dev/null | sed 's/^v//')
    if [ -n "$ver" ]; then
      echo "version=${ver} path=${candidate}"
      emitted=$((emitted+1))
    fi
  fi
done

# Last-resort PATH lookup so a custom install we didn't enumerate still shows.
if [ "$emitted" = "0" ]; then
  pathnode=$(command -v node 2>/dev/null || true)
  if [ -n "$pathnode" ]; then
    ver=$("$pathnode" -v 2>/dev/null | sed 's/^v//')
    if [ -n "$ver" ]; then
      echo "version=${ver} path=${pathnode}"
      emitted=$((emitted+1))
    fi
  fi
fi

if [ "$emitted" = "0" ]; then
  echo "no-node"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-nodejs-eol', $script, 20, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.nodejs_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-node')) {
            return [];
        }

        // De-duplicate by major version — running 18.16 and 18.20 on the same
        // server is the same risk story.
        $installations = [];
        foreach (preg_split("/\r?\n/", $buffer) ?: [] as $line) {
            if (! preg_match('/^version=(\d+)\.(\d+)\.(\d+)\s+path=(.+)$/', trim($line), $m)) {
                continue;
            }
            $major = (int) $m[1];
            $installations[$major] ??= [
                'major' => $major,
                'versions' => [],
                'paths' => [],
            ];
            $installations[$major]['versions'][] = $m[1].'.'.$m[2].'.'.$m[3];
            $installations[$major]['paths'][] = $m[4];
        }

        if ($installations === []) {
            return [];
        }

        $eolMap = config('insights_nodejs_eol', []);
        $warnDays = max(1, (int) ($parameters['warn_days'] ?? 90));

        $critical = [];
        $warn = [];

        foreach ($installations as $row) {
            $eolStr = $eolMap[(string) $row['major']] ?? null;
            if (! is_string($eolStr)) {
                continue;
            }
            try {
                $eolDate = Carbon::parse($eolStr);
            } catch (\Throwable) {
                continue;
            }
            $daysToEol = (int) floor(now()->diffInRealSeconds($eolDate, false) / 86400);
            $rowWithEol = $row + ['eol_date' => $eolStr, 'days_to_eol' => $daysToEol];
            if ($daysToEol < 0) {
                $critical[] = $rowWithEol;
            } elseif ($daysToEol <= $warnDays) {
                $warn[] = $rowWithEol;
            }
        }

        if ($critical === [] && $warn === []) {
            return [];
        }

        $severity = $critical !== [] ? InsightFinding::SEVERITY_CRITICAL : InsightFinding::SEVERITY_WARNING;
        $allRows = array_merge($critical, $warn);

        $bodyParts = [];
        foreach ($critical as $row) {
            $bodyParts[] = __('Node.js :major is past EOL (:date).', [
                'major' => $row['major'],
                'date' => $row['eol_date'],
            ]);
        }
        foreach ($warn as $row) {
            $bodyParts[] = __('Node.js :major reaches EOL in :days days (:date).', [
                'major' => $row['major'],
                'days' => $row['days_to_eol'],
                'date' => $row['eol_date'],
            ]);
        }

        return [
            new InsightCandidate(
                insightKey: 'nodejs_updates',
                dedupeHash: 'node-eol-'.md5(implode(',', array_map(static fn (array $r) => $r['major'], $allRows))),
                severity: $severity,
                title: trans_choice(
                    '{1} 1 Node.js version is at or near end of life|[2,*] :count Node.js versions are at or near end of life',
                    count($allRows),
                    ['count' => count($allRows)],
                ),
                body: implode("\n", $bodyParts)."\n".__('Plan an upgrade — Node majors stop receiving security fixes after EOL. Migrate sites pinned to old majors to a current LTS.'),
                meta: [
                    'signal' => [
                        'installations' => array_values($installations),
                        'critical' => $critical,
                        'warning' => $warn,
                    ],
                ],
            ),
        ];
    }
}
