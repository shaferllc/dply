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
     * @return array<int, InsightCandidate>
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
# How long since apt last refreshed its index. A repo that cannot verify makes
# every `apt-get update` fail, the lists stop advancing, and the counts below
# then describe a snapshot from weeks ago rather than the host today.
index_age_days=-1
newest=$(ls -t /var/lib/apt/lists/*Release 2>/dev/null | head -n1)
if [ -z "$newest" ]; then newest=$(ls -t /var/lib/apt/lists/ 2>/dev/null | head -n1); fi
if [ -n "$newest" ]; then
  ts=$(stat -c %Y "/var/lib/apt/lists/$(basename "$newest")" 2>/dev/null || stat -c %Y "$newest" 2>/dev/null || echo 0)
  if [ "${ts:-0}" -gt 0 ]; then index_age_days=$(( ( $(date +%s) - ts ) / 86400 )); fi
fi
echo "index_age_days=${index_age_days}"
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
        $security = (int) ($values['security'] ?? 0);
        $total = (int) ($values['total'] ?? 0);

        $candidates = [];

        // A stale index makes this insight quieter, not louder: `apt list
        // --upgradable` reads the cached lists, so a host whose apt has been
        // broken for weeks reports few or no pending security updates and looks
        // healthier the longer it stays broken. Surface that inversion before
        // trusting either count.
        $indexAgeDays = (int) ($values['index_age_days'] ?? -1);
        $maxIndexAge = max(1, (int) ($parameters['max_index_age_days'] ?? 7));

        if ($indexAgeDays > $maxIndexAge) {
            $candidates[] = new InsightCandidate(
                insightKey: 'apt_index_stale',
                dedupeHash: 'apt-index-stale',
                severity: $indexAgeDays > ($maxIndexAge * 4)
                    ? InsightFinding::SEVERITY_CRITICAL
                    : InsightFinding::SEVERITY_WARNING,
                title: __('apt has not refreshed its package index in :days days', ['days' => $indexAgeDays]),
                body: __('The package index is :days days old, so the security-update count below is measured against a stale snapshot and understates what this host is missing. Usually a repository that can no longer be verified — an expired signing key fails the whole update. Run `dply server apt-repair <server> --dry-run`, or `sudo apt-get update` on the host to see which source is failing.', ['days' => $indexAgeDays]),
                meta: [
                    'signal' => [
                        'index_age_days' => $indexAgeDays,
                        'max_index_age_days' => $maxIndexAge,
                    ],
                ],
            );
        }

        $threshold = max(0, (int) ($parameters['min_security_updates'] ?? 1));
        if ($security < $threshold || $security <= 0) {
            return $candidates;
        }

        $severity = $security >= 10
            ? InsightFinding::SEVERITY_CRITICAL
            : InsightFinding::SEVERITY_WARNING;

        $candidates[] = new InsightCandidate(
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
                    'index_age_days' => $indexAgeDays,
                ],
            ],
        );

        return $candidates;
    }

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
