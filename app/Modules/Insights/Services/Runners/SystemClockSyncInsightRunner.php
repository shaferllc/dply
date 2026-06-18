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
 * Detect system clock drift / unsynchronized NTP. Probes `timedatectl` once per scheduled run
 * and parses two boolean lines: NTP service active and System clock synchronized. When either
 * is "no", emits a `kind=problem` finding (clock skew silently breaks JWT/cookie expiry,
 * TLS validity, log correlation, and `at`-style scheduling).
 */
class SystemClockSyncInsightRunner implements InsightRunnerInterface
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
if ! command -v timedatectl >/dev/null 2>&1; then
  echo "no-timedatectl"
  exit 0
fi
timedatectl status 2>/dev/null | awk -F: '
/NTP service/             { gsub(/[ \t]+/, "", $2); print "ntp_service=" $2 }
/System clock synchronized/ { gsub(/[ \t]+/, "", $2); print "synchronized=" $2 }
/Time zone/                { sub(/^[ \t]*/, "", $2); print "timezone=" $2 }
'
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-clock-probe', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.clock_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-timedatectl')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $ntpRaw = strtolower($values['ntp_service'] ?? '');
        $syncRaw = strtolower($values['synchronized'] ?? '');

        if ($ntpRaw === '' && $syncRaw === '') {
            // timedatectl returned nothing parseable — don't emit a noisy finding.
            return [];
        }

        $ntpActive = in_array($ntpRaw, ['active', 'yes'], true);
        $synchronized = $syncRaw === 'yes';

        if ($ntpActive && $synchronized) {
            return [];
        }

        $reasons = [];
        if (! $ntpActive) {
            $reasons[] = __('NTP service is not active');
        }
        if (! $synchronized) {
            $reasons[] = __('system clock is not reported as synchronized');
        }

        return [
            new InsightCandidate(
                insightKey: 'system_clock_sync',
                dedupeHash: 'clock',
                severity: InsightFinding::SEVERITY_WARNING,
                title: __('System clock is not synchronized'),
                body: __('Detected: :reasons. Clock drift can break TLS validation, token expiry, and log correlation. Apply-fix runs `timedatectl set-ntp true`.', [
                    'reasons' => implode('; ', $reasons),
                ]),
                meta: [
                    'signal' => [
                        'ntp_service' => $values['ntp_service'] ?? null,
                        'synchronized' => $values['synchronized'] ?? null,
                        'timezone' => $values['timezone'] ?? null,
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
