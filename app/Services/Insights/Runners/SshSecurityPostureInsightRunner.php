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
 * SSH daemon posture: probe the effective sshd config (via `sshd -T`) and flag
 * common insecure-by-default options. Combines several individual checks under
 * one finding because operators usually want to read them together — fixing
 * PasswordAuthentication in isolation while PermitRootLogin is still yes is
 * not a meaningful improvement.
 *
 * Checks:
 *  - PasswordAuthentication yes  (warn)        — keys-only auth is the baseline
 *  - PermitRootLogin yes         (critical)    — root logins should be disabled
 *  - PermitEmptyPasswords yes    (critical)    — should never be on
 *  - X11Forwarding yes           (info)        — generally unnecessary on servers
 *  - sshd protocol 1             (critical)    — vanishingly rare on modern distros, but worth flagging
 *
 * Severity is the highest of the individual findings.
 */
class SshSecurityPostureInsightRunner implements InsightRunnerInterface
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

        // `sshd -T` prints the effective configuration (after parsing
        // /etc/ssh/sshd_config + Include files). It requires root, which our
        // operational SSH user has via sudo on a Dply-provisioned box.
        $script = <<<'BASH'
if ! command -v sshd >/dev/null 2>&1; then
  echo "no-sshd"
  exit 0
fi
sudo -n sshd -T 2>/dev/null | awk '
BEGIN { IGNORECASE=1 }
/^passwordauthentication / { print "password_authentication=" $2 }
/^permitrootlogin /        { print "permit_root_login=" $2 }
/^permitemptypasswords /   { print "permit_empty_passwords=" $2 }
/^x11forwarding /          { print "x11_forwarding=" $2 }
/^protocol /               { print "protocol=" $2 }
'
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-sshd-posture', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.sshd_posture_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-sshd')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);

        // Empty parse means sshd -T was blocked (no sudo) or returned nothing.
        // Don't emit a noisy "no data" finding — silent skip until the next run.
        if ($values === []) {
            return [];
        }

        $passwordAuth = strtolower($values['password_authentication'] ?? '');
        $rootLogin = strtolower($values['permit_root_login'] ?? '');
        $emptyPasswords = strtolower($values['permit_empty_passwords'] ?? '');
        $x11 = strtolower($values['x11_forwarding'] ?? '');
        $protocol = (int) ($values['protocol'] ?? 2);

        $issues = [];
        $maxSeverity = null;

        if ($passwordAuth === 'yes') {
            $issues[] = __('Password authentication is enabled — switch sshd to key-only auth.');
            $maxSeverity = $this->raise($maxSeverity, InsightFinding::SEVERITY_WARNING);
        }
        // OpenSSH accepts: yes | no | prohibit-password | forced-commands-only.
        // "no" and "prohibit-password" (the modern default) are both fine.
        if ($rootLogin === 'yes') {
            $issues[] = __('Root login is allowed over SSH — disable it (PermitRootLogin no or prohibit-password).');
            $maxSeverity = $this->raise($maxSeverity, InsightFinding::SEVERITY_CRITICAL);
        }
        if ($emptyPasswords === 'yes') {
            $issues[] = __('Empty passwords are permitted — set PermitEmptyPasswords no.');
            $maxSeverity = $this->raise($maxSeverity, InsightFinding::SEVERITY_CRITICAL);
        }
        if ($protocol === 1) {
            $issues[] = __('sshd is configured for protocol 1 (deprecated and insecure).');
            $maxSeverity = $this->raise($maxSeverity, InsightFinding::SEVERITY_CRITICAL);
        }
        if ($x11 === 'yes') {
            $issues[] = __('X11 forwarding is enabled — usually unnecessary on a server.');
            $maxSeverity = $this->raise($maxSeverity, InsightFinding::SEVERITY_INFO);
        }

        if ($issues === [] || $maxSeverity === null) {
            return [];
        }

        // dedupe_hash includes the set of flagged options so the finding
        // upserts (rather than re-fires) when the same options remain
        // insecure, but reopens cleanly if a new option becomes risky.
        $hash = md5(implode('|', [
            'pwauth='.$passwordAuth,
            'root='.$rootLogin,
            'empty='.$emptyPasswords,
            'proto='.$protocol,
            'x11='.$x11,
        ]));

        return [
            new InsightCandidate(
                insightKey: 'ssh_security_posture',
                dedupeHash: 'sshd-'.$hash,
                severity: $maxSeverity,
                title: trans_choice(
                    '{1} SSH daemon has 1 insecure setting|[2,*] SSH daemon has :count insecure settings',
                    count($issues),
                    ['count' => count($issues)],
                ),
                body: implode("\n", $issues),
                meta: [
                    'signal' => [
                        'password_authentication' => $values['password_authentication'] ?? null,
                        'permit_root_login' => $values['permit_root_login'] ?? null,
                        'permit_empty_passwords' => $values['permit_empty_passwords'] ?? null,
                        'x11_forwarding' => $values['x11_forwarding'] ?? null,
                        'protocol' => $values['protocol'] ?? null,
                    ],
                    'issues' => $issues,
                ],
            ),
        ];
    }

    /**
     * Pick the higher-priority severity between two values (CRITICAL > WARNING > INFO).
     */
    private function raise(?string $current, string $candidate): string
    {
        $rank = [
            InsightFinding::SEVERITY_INFO => 10,
            InsightFinding::SEVERITY_WARNING => 20,
            InsightFinding::SEVERITY_CRITICAL => 30,
        ];

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
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
