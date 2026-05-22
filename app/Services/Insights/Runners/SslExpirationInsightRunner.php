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
 * SSL certificate expiration countdown — complement to {@see SslCertificateInsightRunner}
 * which only checks issuance state. This probes the actual cert NotAfter date over
 * loopback from the box itself (so we see what visitors see, not what Let's Encrypt
 * thinks), and emits warn (≤30 days) or critical (≤14 days) when expiry is near.
 *
 * Runs per-site. Skipped for sites whose ssl_status is not active (the sibling
 * runner already flags those). Skipped on hosts that don't have openssl.
 */
class SslExpirationInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site === null || $server->id !== $site->server_id) {
            return [];
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            return [];
        }
        if ($site->ssl_status !== Site::SSL_ACTIVE) {
            // The other SSL runner handles non-active states; doubling up would
            // produce two findings for the same root cause.
            return [];
        }

        $hostname = optional($site->primaryDomain())->hostname;
        if (! is_string($hostname) || $hostname === '') {
            return [];
        }

        $warnDays = max(1, (int) ($parameters['warn_days'] ?? 30));
        $criticalDays = max(1, (int) ($parameters['critical_days'] ?? 14));
        if ($criticalDays > $warnDays) {
            $criticalDays = $warnDays;
        }

        $hostnameEscaped = escapeshellarg($hostname);
        // Use timeout(1) so a sslv3-rejecting endpoint can't hang the probe.
        // -servername sends SNI so vhosts return the right cert.
        $script = <<<BASH
if ! command -v openssl >/dev/null 2>&1; then
  echo "no-openssl"
  exit 0
fi
HOST={$hostnameEscaped}
OUT=\$(timeout 15 bash -c "echo | openssl s_client -servername \$HOST -connect \$HOST:443 -verify_return_error 2>/dev/null | openssl x509 -noout -enddate -subject 2>/dev/null")
EC=\$?
if [ \$EC -ne 0 ] || [ -z "\$OUT" ]; then
  echo "probe-failed exit=\$EC"
  exit 0
fi
printf '%s\\n' "\$OUT" | awk -F= '
/^notAfter/ { print "not_after=" \$2 }
/^subject/  { sub(/^subject *= */, "", \$0); print "subject=" \$0 }
'
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-ssl-expiry-'.$site->id, $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.ssl_expiry_probe_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (str_contains($buffer, 'no-openssl') || str_contains($buffer, 'probe-failed')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $notAfterRaw = $values['not_after'] ?? null;
        if (! is_string($notAfterRaw) || trim($notAfterRaw) === '') {
            return [];
        }

        try {
            $notAfter = Carbon::parse($notAfterRaw);
        } catch (\Throwable $e) {
            Log::debug('insights.ssl_expiry_parse_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'not_after_raw' => $notAfterRaw,
            ]);

            return [];
        }

        $daysToExpiry = (int) floor(now()->diffInRealSeconds($notAfter, false) / 86400);

        // Already expired: critical with a distinct title; ≤critical days: critical;
        // ≤warn days: warning. Outside the warn window: emit nothing (let the
        // recorder auto-resolve any open finding).
        if ($daysToExpiry > $warnDays) {
            return [];
        }

        if ($daysToExpiry < 0) {
            $severity = InsightFinding::SEVERITY_CRITICAL;
            $title = __('SSL certificate has expired for :host', ['host' => $hostname]);
        } elseif ($daysToExpiry <= $criticalDays) {
            $severity = InsightFinding::SEVERITY_CRITICAL;
            $title = trans_choice(
                '{0} SSL certificate expires today for :host|{1} SSL certificate expires tomorrow for :host|[2,*] SSL certificate expires in :days days for :host',
                $daysToExpiry,
                ['days' => $daysToExpiry, 'host' => $hostname],
            );
        } else {
            $severity = InsightFinding::SEVERITY_WARNING;
            $title = __('SSL certificate expires in :days days for :host', [
                'days' => $daysToExpiry,
                'host' => $hostname,
            ]);
        }

        return [
            new InsightCandidate(
                insightKey: 'ssl_certificate_expiring',
                dedupeHash: 'ssl-exp-'.md5($hostname),
                severity: $severity,
                title: $title,
                body: __('Cert expires :when (UTC). If Let\'s Encrypt is configured for this site, renewal is automatic — check the renewal cron and recent logs. Otherwise reissue from the Site → SSL page.', [
                    'when' => $notAfter->utc()->toDateTimeString(),
                ]),
                meta: [
                    'signal' => [
                        'hostname' => $hostname,
                        'not_after' => $notAfter->toIso8601String(),
                        'days_to_expiry' => $daysToExpiry,
                        'subject' => $values['subject'] ?? null,
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
