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
 * Reboot-required detector. Debian/Ubuntu drops /var/run/reboot-required after
 * a kernel or libc upgrade that the running processes won't pick up until reboot.
 * /var/run/reboot-required.pkgs lists which packages requested it.
 *
 * Severity is normally warning; escalates to critical if the flag file has been
 * sitting there longer than `critical_after_days` (default 14) — that means the
 * operator has been ignoring kernel patches for two weeks, which is the kind of
 * thing that ends up in a postmortem.
 */
class RebootRequiredInsightRunner implements InsightRunnerInterface
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
if [ ! -f /var/run/reboot-required ]; then
  echo "no-reboot-required"
  exit 0
fi
# stat -c %Y prints the unix mtime (when the flag was first dropped).
ts=$(stat -c %Y /var/run/reboot-required 2>/dev/null || echo 0)
echo "reboot_required=yes"
echo "mtime_ts=${ts}"
if [ -f /var/run/reboot-required.pkgs ]; then
  # First 30 package names, comma-separated, single line.
  pkgs=$(head -n 30 /var/run/reboot-required.pkgs | tr '\n' ',' | sed 's/,$//')
  echo "packages=${pkgs}"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-reboot-required', $script, 15, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.reboot_required_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-reboot-required')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        if (strtolower($values['reboot_required'] ?? '') !== 'yes') {
            return [];
        }

        $mtime = (int) ($values['mtime_ts'] ?? 0);
        $ageDays = $mtime > 0 ? (int) floor((time() - $mtime) / 86400) : null;
        $criticalAfter = max(1, (int) ($parameters['critical_after_days'] ?? 14));
        $severity = $ageDays !== null && $ageDays >= $criticalAfter
            ? InsightFinding::SEVERITY_CRITICAL
            : InsightFinding::SEVERITY_WARNING;

        $packages = isset($values['packages']) && $values['packages'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $values['packages']))))
            : [];

        $body = $ageDays !== null && $ageDays > 0
            ? __('Pending since :days days ago. Schedule a maintenance window — kernel/libc fixes won\'t take effect until the host reboots.', ['days' => $ageDays])
            : __('Schedule a maintenance window — kernel/libc fixes won\'t take effect until the host reboots.');

        if ($packages !== []) {
            $body .= "\n".__('Triggered by: :pkgs', ['pkgs' => implode(', ', array_slice($packages, 0, 10))]);
        }

        return [
            new InsightCandidate(
                insightKey: 'reboot_required',
                dedupeHash: 'reboot-required',
                severity: $severity,
                title: __('Reboot required to apply pending updates'),
                body: $body,
                meta: [
                    'signal' => [
                        'mtime_ts' => $mtime,
                        'age_days' => $ageDays,
                        'packages' => $packages,
                    ],
                ],
            ),
        ];
    }

    /**
     * @return array<string, string>
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
