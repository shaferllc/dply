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
 * Failed systemd units detector. `systemctl --failed --plain --no-legend` lists
 * units currently in failed state. Persistent failures here are operator-
 * relevant: a unit could be a crashed daemon, an exec timer that errored, or
 * something the user has never noticed because nothing else points at it.
 */
class FailedSystemdUnitsInsightRunner implements InsightRunnerInterface
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
if ! command -v systemctl >/dev/null 2>&1; then
  echo "no-systemctl"
  exit 0
fi
# --plain strips dots, --no-legend drops the trailing summary, awk pulls the
# first column which is the unit name. Capped at 50 to bound the meta payload.
systemctl --failed --plain --no-legend 2>/dev/null | awk '{ print $1 }' | head -n 50
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-systemd-failed', $script, 20, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.systemd_failed_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-systemctl')) {
            return [];
        }

        $units = array_values(array_filter(
            array_map('trim', explode("\n", $buffer)),
            static fn (string $line): bool => $line !== '' && ! str_contains($line, '='),
        ));

        if ($units === []) {
            return [];
        }

        // Two thresholds: any failed unit is a warning; 3+ is critical (it
        // usually means a deeper issue like an apt failure or storage problem
        // that cascaded into multiple services).
        $critical = (int) ($parameters['critical_count'] ?? 3);
        $severity = count($units) >= $critical
            ? InsightFinding::SEVERITY_CRITICAL
            : InsightFinding::SEVERITY_WARNING;

        return [
            new InsightCandidate(
                insightKey: 'systemd_failed_units',
                // Hash on the unit set, not the count — so a stable failing
                // unit upserts the existing finding, and the finding reopens
                // cleanly if a new unit fails.
                dedupeHash: 'failed-'.md5(implode(',', $units)),
                severity: $severity,
                title: trans_choice(
                    '{1} 1 systemd unit is in a failed state|[2,*] :count systemd units are in a failed state',
                    count($units),
                    ['count' => count($units)],
                ),
                body: __('Run `systemctl status <unit>` to inspect each, then `systemctl reset-failed <unit>` after fixing. Affected: :units', [
                    'units' => implode(', ', array_slice($units, 0, 8)).(count($units) > 8 ? ', …' : ''),
                ]),
                meta: [
                    'signal' => [
                        'failed_count' => count($units),
                        'failed_units' => $units,
                    ],
                ],
            ),
        ];
    }
}
