<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Detect outstanding security updates via `apt list --upgradable`. On Ubuntu/Debian,
 * security updates come from `*-security` suites — e.g. `jammy-security`. Counts those
 * lines and emits a problem-class finding when any are present. No apply-fix: running
 * `apt upgrade` is too disruptive to be a one-click action (can restart services / kernels);
 * the body links the user to run it themselves under their own change-control window.
 */
class PackageSecurityUpdatesInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array<int, App\Services\Insights\InsightCandidate>
     */
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            return [];
        }

        $script = <<<'BASH'
if ! command -v apt >/dev/null 2>&1 && ! command -v apt-get >/dev/null 2>&1; then
  echo "no-apt"
  exit 0
fi
# `apt list --upgradable` writes a leading "Listing..." header on stderr; the data lines
# include the suite (e.g. jammy-security) which we grep for. Count separately so the
# probe is robust to grep returning 1 when there are 0 matches.
total=$(apt list --upgradable 2>/dev/null | tail -n +2 | wc -l | tr -d '[:space:]')
security=$(apt list --upgradable 2>/dev/null | tail -n +2 | grep -E -- '(-security|-updates-security)' | wc -l | tr -d '[:space:]')
if [ -z "$total" ];    then total=0;    fi
if [ -z "$security" ]; then security=0; fi
echo "total=${total}"
echo "security=${security}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-apt-security-updates', $script, 60, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.apt_security_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-apt')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $security = (int) ($values['security']);
        $total = (int) ($values['total']);

        $threshold = max(0, (int) ($parameters['min_security_updates'] ?? 1));
        if ($security < $threshold || $security <= 0) {
            return [];
        }

        $severity = $security >= 10
            ? InsightFinding::SEVERITY_CRITICAL
            : InsightFinding::SEVERITY_WARNING;

        return [
            new InsightCandidate(
                insightKey: 'package_security_updates',
                dedupeHash: 'apt-security',
                severity: $severity,
                title: trans_choice(
                    '{1} :count security update available|[2,*] :count security updates available',
                    $security,
                    ['count' => $security],
                ),
                body: __(':sec security updates of :total upgradable packages. Run `sudo apt update && sudo apt upgrade` during a maintenance window — this can restart services and may require a reboot.', [
                    'sec' => $security,
                    'total' => $total,
                ]),
                meta: [
                    'signal' => [
                        'security_count' => $security,
                        'total_upgradable' => $total,
                    ],
                ],
            ),
        ];
    }

    /**
     * @return array<int, App\Services\Insights\InsightCandidate>
     */
    private function parseKeyValues(string $buffer): array
    {
        $out = [];
        foreach (explode("\n", $buffer) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }

        return $out;
    }
}
