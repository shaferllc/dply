<?php

namespace App\Modules\Insights\Services\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Modules\Insights\Services\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Detect PHP OPcache disabled in the FPM SAPI. Iterates installed PHP versions
 * and reads each FPM ini stack for opcache.enable. A site running PHP without
 * opcache enabled wastes 30-50% of every request opcompiling source. Common
 * causes:
 *   - opcache extension missing (apt didn't install php-opcache)
 *   - opcache.enable=0 in php.ini
 *   - opcache.enable_cli=1 but FPM disabled (we only flag the FPM side)
 *
 * Per-version detail goes into meta.signal.versions so the finding body can
 * tell the operator which version to look at.
 */
class OpcacheDisabledInsightRunner implements InsightRunnerInterface
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
# For each /etc/php/<ver>/fpm tree, ask the FPM-flavored CLI to report opcache.enable.
# Using -c on the fpm php.ini ensures we're checking the same ini stack FPM sees.
shopt -s nullglob
for d in /etc/php/*/fpm; do
  ver=$(basename "$(dirname "$d")")
  bin="/usr/bin/php${ver}"
  if [ ! -x "$bin" ]; then
    bin=$(command -v php || true)
  fi
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    echo "version=${ver} enabled=unknown reason=binary-missing"
    continue
  fi
  enabled=$("$bin" -c "$d/php.ini" -r 'echo (extension_loaded("Zend OPcache") && ini_get("opcache.enable")) ? "yes" : "no";' 2>/dev/null || echo "error")
  reason=ok
  if [ "$enabled" = "error" ]; then
    reason=probe-failed
    enabled=unknown
  elif [ "$enabled" = "no" ]; then
    if "$bin" -c "$d/php.ini" -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' 2>/dev/null; then
      reason=extension-loaded-but-disabled
    else
      reason=extension-missing
    fi
  fi
  echo "version=${ver} enabled=${enabled} reason=${reason}"
done
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-opcache-probe', $script, 30, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.opcache_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        $versions = [];
        foreach (preg_split("/\r?\n/", $buffer) ?: [] as $line) {
            $line = trim($line);
            if (! preg_match('/^version=(\S+)\s+enabled=(\S+)\s+reason=(\S+)/', $line, $m)) {
                continue;
            }
            $versions[$m[1]] = ['enabled' => $m[2], 'reason' => $m[3]];
        }

        if ($versions === []) {
            return [];
        }

        $offenders = array_filter($versions, static fn (array $v): bool => $v['enabled'] === 'no');
        if ($offenders === []) {
            return [];
        }

        $offendingVersions = array_keys($offenders);

        return [
            new InsightCandidate(
                insightKey: 'opcache_disabled',
                dedupeHash: 'opcache-off-'.md5(implode(',', $offendingVersions)),
                severity: InsightFinding::SEVERITY_WARNING,
                title: trans_choice(
                    '{1} PHP OPcache is disabled for 1 version|[2,*] PHP OPcache is disabled for :count versions',
                    count($offendingVersions),
                    ['count' => count($offendingVersions)],
                ),
                body: __('Affects: :versions. Enabling opcache typically cuts 30–50% off PHP request time. Edit /etc/php/<version>/fpm/php.ini and set opcache.enable=1 (and install php-opcache if the reason says extension-missing).', [
                    'versions' => implode(', ', array_map(
                        static fn (string $v) => $v.' ('.$offenders[$v]['reason'].')',
                        $offendingVersions,
                    )),
                ]),
                meta: [
                    'signal' => [
                        'versions' => $versions,
                        'offending_versions' => $offendingVersions,
                    ],
                ],
            ),
        ];
    }
}
