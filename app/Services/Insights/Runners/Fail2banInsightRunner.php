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
 * fail2ban presence/activity check. fail2ban scans log files (auth.log, etc.)
 * and bans hosts repeating auth failures. Not having it on an internet-facing
 * box won't break anything but does meaningfully widen the brute-force window.
 *
 * Three states:
 *  - Not installed              → suggestion (operator may have opted out)
 *  - Installed but not active   → warning (config drift, broken update)
 *  - Active                     → no finding
 */
class Fail2banInsightRunner implements InsightRunnerInterface
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
if command -v dpkg >/dev/null 2>&1; then
  if dpkg-query -W -f='${Status}' fail2ban 2>/dev/null | grep -q "install ok installed"; then
    echo "installed=yes"
  else
    echo "installed=no"
  fi
elif command -v rpm >/dev/null 2>&1; then
  if rpm -q fail2ban >/dev/null 2>&1; then
    echo "installed=yes"
  else
    echo "installed=no"
  fi
else
  echo "no-pkg-manager"
  exit 0
fi

if command -v systemctl >/dev/null 2>&1; then
  state=$(systemctl is-active fail2ban 2>/dev/null || echo unknown)
  echo "state=${state}"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fail2ban', $script, 15, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.fail2ban_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-pkg-manager')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $installed = ($values['installed'] ?? '') === 'yes';
        $state = strtolower($values['state'] ?? '');

        if ($installed && $state === 'active') {
            return [];
        }

        if (! $installed) {
            return [
                new InsightCandidate(
                    insightKey: 'fail2ban_inactive',
                    dedupeHash: 'fail2ban-missing',
                    severity: InsightFinding::SEVERITY_INFO,
                    title: __('fail2ban is not installed'),
                    body: __('fail2ban watches auth logs and temp-bans hosts hammering SSH. Optional but recommended on internet-facing boxes. Install with `apt install fail2ban`.'),
                    meta: ['signal' => ['installed' => 'no']],
                    kind: InsightFinding::KIND_SUGGESTION,
                ),
            ];
        }

        // Installed but not running — that's a problem, not a suggestion.
        return [
            new InsightCandidate(
                insightKey: 'fail2ban_inactive',
                dedupeHash: 'fail2ban-inactive-'.$state,
                severity: InsightFinding::SEVERITY_WARNING,
                title: __('fail2ban is installed but not running'),
                body: __('Service reports state ":state". Run `systemctl status fail2ban` and `journalctl -u fail2ban -n 50` to see why it stopped.', ['state' => $state]),
                meta: ['signal' => ['installed' => 'yes', 'state' => $state]],
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
