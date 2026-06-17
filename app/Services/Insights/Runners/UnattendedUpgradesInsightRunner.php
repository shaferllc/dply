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
 * Detect when unattended-upgrades is missing or disabled on Debian/Ubuntu. This
 * is the canonical mechanism for applying security updates automatically; if
 * it's off, package_security_updates will accumulate forever between manual
 * apt runs.
 *
 * Three states:
 *  - Package not installed     → suggestion-class, encourage `apt install unattended-upgrades`
 *  - Installed but disabled    → warning (config flipped to off)
 *  - Timer/service inactive    → warning (systemd timer not started)
 */
class UnattendedUpgradesInsightRunner implements InsightRunnerInterface
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
if ! command -v dpkg >/dev/null 2>&1; then
  echo "no-dpkg"
  exit 0
fi
# Installed when dpkg reports "install ok installed".
if dpkg-query -W -f='${Status}' unattended-upgrades 2>/dev/null | grep -q "install ok installed"; then
  echo "installed=yes"
else
  echo "installed=no"
fi

# 20auto-upgrades enables periodic + unattended runs. Either file or its parent
# being absent means apt's periodic system won't fire unattended-upgrades.
auto_file=/etc/apt/apt.conf.d/20auto-upgrades
if [ -r "$auto_file" ]; then
  unattended=$(grep -E '^[[:space:]]*APT::Periodic::Unattended-Upgrade\s+"1"' "$auto_file" 2>/dev/null | wc -l | tr -d '[:space:]')
  echo "auto_upgrades_file=present"
  echo "unattended_enabled=$([ "$unattended" != "0" ] && echo yes || echo no)"
else
  echo "auto_upgrades_file=missing"
  echo "unattended_enabled=unknown"
fi

# Systemd timer presence/active. `is-active` prints the state to stdout AND
# exits non-zero for any non-active state, so capture stdout first and fall
# back to `unknown` only when stdout is empty (unit missing, systemctl broken).
# Composing `... || echo unknown` inside the same $() concatenates "inactive"
# and "unknown" into the captured value — don't do that.
if command -v systemctl >/dev/null 2>&1; then
  if systemctl list-unit-files apt-daily-upgrade.timer 2>/dev/null | grep -q apt-daily-upgrade.timer; then
    echo "timer_present=yes"
    state=$(systemctl is-active apt-daily-upgrade.timer 2>/dev/null || true)
    state=${state:-unknown}
    echo "timer_state=${state}"
  else
    echo "timer_present=no"
    echo "timer_state=missing"
  fi
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-unattended-upgrades', $script, 20, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.unattended_upgrades_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-dpkg')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        if ($values === []) {
            return [];
        }

        $installed = ($values['installed'] ?? '') === 'yes';
        $unattendedEnabled = strtolower($values['unattended_enabled'] ?? '') === 'yes';
        $timerActive = strtolower($values['timer_state'] ?? '') === 'active';

        // All three on = nothing to flag.
        if ($installed && $unattendedEnabled && $timerActive) {
            return [];
        }

        $reasons = [];
        $severity = InsightFinding::SEVERITY_INFO;
        $kind = InsightFinding::KIND_SUGGESTION;

        if (! $installed) {
            $reasons[] = __('unattended-upgrades is not installed — run `apt install unattended-upgrades` to opt in.');
            // Suggestion-class so it can be ignored on hosts where the
            // operator does updates manually.
            $severity = InsightFinding::SEVERITY_INFO;
            $kind = InsightFinding::KIND_SUGGESTION;
        } else {
            // Once installed, the user clearly intended automatic upgrades; a
            // misconfiguration here is a problem, not a suggestion.
            $kind = InsightFinding::KIND_PROBLEM;
            $severity = InsightFinding::SEVERITY_WARNING;

            if (! $unattendedEnabled) {
                $reasons[] = __('unattended-upgrades is installed but disabled in /etc/apt/apt.conf.d/20auto-upgrades.');
            }
            if (! $timerActive) {
                $reasons[] = __('The apt-daily-upgrade.timer systemd unit is not active.');
            }
        }

        if ($reasons === []) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'unattended_upgrades_disabled',
                dedupeHash: 'uu-'.md5(implode('|', array_keys($values)).implode('|', array_values($values))),
                severity: $severity,
                title: $installed
                    ? __('Unattended security upgrades aren\'t running')
                    : __('Automatic security upgrades aren\'t configured'),
                body: implode("\n", $reasons),
                meta: [
                    'signal' => [
                        'installed' => $values['installed'],
                        'auto_upgrades_file' => $values['auto_upgrades_file'],
                        'unattended_enabled' => $values['unattended_enabled'],
                        'timer_present' => $values['timer_present'],
                        'timer_state' => $values['timer_state'],
                    ],
                ],
                kind: $kind,
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
