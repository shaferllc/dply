<?php

namespace App\Services\Certificates;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Models\ServerWildcardCertificate;
use App\Services\Servers\OpenLiteSpeedTlsConfigurator;
use App\Services\SshConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues a per-(server, zone) wildcard TLS certificate (e.g. *.on-dply.com) via
 * certbot DNS-01 with --manual auth/cleanup hooks. The hooks are generic bash
 * scripts that source a root-only creds file and curl the controlling DNS
 * provider's API (DigitalOcean / Hetzner / Cloudflare) to set and clear the
 * `_acme-challenge` TXT record — no per-provider certbot plugin installs.
 *
 * Only the wildcard (`*.<zone>`) is requested — testing hostnames are always
 * subdomains, so the bare apex is unnecessary and dropping it keeps the
 * challenge to a single TXT value (no wildcard+apex dual-record handling).
 * certbot stores the result under /etc/letsencrypt/live/<zone>/.
 */
class WildcardCertificateIssuer
{
    use PrivilegedRemoteFileWrites;

    public function __construct(
        private readonly OpenLiteSpeedTlsConfigurator $openLiteSpeedTlsConfigurator,
    ) {}

    public function issue(ServerWildcardCertificate $wildcard): ServerWildcardCertificate
    {
        $wildcard->loadMissing(['server.organization', 'providerCredential']);
        $server = $wildcard->server;

        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key to issue a wildcard certificate.');
        }

        $zone = strtolower(trim((string) $wildcard->zone));
        if ($zone === '') {
            throw new \InvalidArgumentException('Wildcard certificate is missing a zone.');
        }

        $provider = strtolower(trim((string) $wildcard->provider));
        if (! in_array($provider, ['digitalocean', 'hetzner', 'cloudflare'], true)) {
            throw new \RuntimeException("DNS-01 wildcard issuance is not implemented for provider [{$provider}].");
        }

        $token = $wildcard->providerCredential?->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            $token = $provider === 'digitalocean'
                ? trim((string) config('services.digitalocean.token'))
                : '';
        }
        if (! is_string($token) || trim($token) === '') {
            throw new \RuntimeException("No DNS API token available for provider [{$provider}] to issue *.{$zone}.");
        }

        $email = config('sites.certbot_email') ?: $server->organization?->email;
        if (! is_string($email) || trim($email) === '') {
            throw new \InvalidArgumentException('Set DPLY_CERTBOT_EMAIL or an organization email for Let\'s Encrypt.');
        }

        $wildcard->forceFill([
            'status' => ServerWildcardCertificate::STATUS_ISSUING,
            'live_directory' => $zone,
            'last_requested_at' => now(),
        ])->save();

        $stamp = Str::lower(Str::random(10));
        $credsPath = "/tmp/dply-acme-{$stamp}.env";
        $authPath = "/tmp/dply-acme-auth-{$stamp}.sh";
        $cleanupPath = "/tmp/dply-acme-cleanup-{$stamp}.sh";
        $liveDir = '/etc/letsencrypt/live/'.$zone;
        $certPath = $liveDir.'/fullchain.pem';
        $keyPath = $liveDir.'/privkey.pem';

        $ssh = new SshConnection($server);

        $this->putRemote($ssh, $server, $credsPath, $this->credsFile($provider, $token, $zone), '600');
        $this->putRemote($ssh, $server, $authPath, $this->hookScript(true, $credsPath), '700');
        $this->putRemote($ssh, $server, $cleanupPath, $this->hookScript(false, $credsPath), '700');

        $certbot = sprintf(
            'certbot certonly --manual --preferred-challenges dns --non-interactive --agree-tos '
            .'--manual-auth-hook %s --manual-cleanup-hook %s -d %s --cert-name %s -m %s --keep-until-expiring',
            escapeshellarg($authPath),
            escapeshellarg($cleanupPath),
            escapeshellarg('*.'.$zone),
            escapeshellarg($zone),
            escapeshellarg($email),
        );

        $command = $certbot
            .'; status=$?; rm -f '.escapeshellarg($credsPath).' '.escapeshellarg($authPath).' '.escapeshellarg($cleanupPath)
            .'; printf "\nDPLY_EXIT:%s" "$status"';

        $output = $ssh->exec($this->privilegedCommand($server, $command), 600);
        $exitCode = preg_match('/DPLY_EXIT:(\d+)/', $output, $m) ? (int) $m[1] : 1;
        $ok = $exitCode === 0;

        if (! $ok) {
            $wildcard->forceFill([
                'status' => ServerWildcardCertificate::STATUS_FAILED,
                'last_output' => $output,
            ])->save();

            throw new \RuntimeException('Wildcard certbot DNS-01 issuance failed (exit '.$exitCode.') for *.'.$zone.'.');
        }

        $notAfter = $this->readNotAfter($ssh, $server, $certPath);

        $wildcard->forceFill([
            'status' => ServerWildcardCertificate::STATUS_ACTIVE,
            'live_directory' => $zone,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'not_after' => $notAfter,
            'last_output' => $output,
            'last_installed_at' => now(),
            'last_renewed_at' => now(),
        ])->save();

        // OpenLiteSpeed needs its listener/vhssl material re-synced after a new
        // cert lands on disk (nginx/Caddy read the live symlink on reload).
        $serverMeta = is_array($server->meta) ? $server->meta : [];
        if (($serverMeta['webserver'] ?? 'nginx') === 'openlitespeed') {
            $this->openLiteSpeedTlsConfigurator->syncServer($server->fresh());
        }

        return $wildcard->fresh();
    }

    private function readNotAfter(SshConnection $ssh, Server $server, string $certPath): ?Carbon
    {
        $cmd = 'openssl x509 -enddate -noout -in '.escapeshellarg($certPath).' 2>/dev/null';
        $out = $ssh->exec($this->privilegedCommand($server, $cmd), 30);

        if (preg_match('/notAfter=(.+)/', $out, $m)) {
            try {
                return Carbon::parse(trim($m[1]));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Root-only env file the hook scripts source for provider, token, and zone.
     */
    private function credsFile(string $provider, string $token, string $zone): string
    {
        return implode("\n", [
            'DPLY_ACME_PROVIDER='.escapeshellarg($provider),
            'DPLY_ACME_TOKEN='.escapeshellarg($token),
            'DPLY_ACME_ZONE='.escapeshellarg($zone),
            '',
        ]);
    }

    /**
     * Generic certbot manual hook. The same provider switch handles both the
     * auth hook (clear stale TXT, create the challenge TXT, wait for
     * propagation) and the cleanup hook (clear the TXT). certbot exports
     * CERTBOT_DOMAIN / CERTBOT_VALIDATION into the hook environment.
     */
    private function hookScript(bool $isAuth, string $credsPath): string
    {
        $body = <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
. __CREDS_PATH__

RECORD_NAME="_acme-challenge"
FQDN="${RECORD_NAME}.${DPLY_ACME_ZONE}"
VALUE="${CERTBOT_VALIDATION:-}"

cf_zone_id() {
  curl -sS -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
    "https://api.cloudflare.com/client/v4/zones?name=${DPLY_ACME_ZONE}&status=active" \
    | grep -o '"id":"[^"]*"' | head -1 | sed 's/"id":"//;s/"//'
}

clear_txt() {
  case "$DPLY_ACME_PROVIDER" in
    digitalocean)
      for id in $(curl -sS -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
        "https://api.digitalocean.com/v2/domains/${DPLY_ACME_ZONE}/records?type=TXT&name=${FQDN}&per_page=200" \
        | grep -o '"id":[0-9]*' | sed 's/"id"://'); do
        curl -sS -X DELETE -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
          "https://api.digitalocean.com/v2/domains/${DPLY_ACME_ZONE}/records/${id}" >/dev/null || true
      done
      ;;
    cloudflare)
      zid="$(cf_zone_id)"
      [ -z "$zid" ] && return 0
      for id in $(curl -sS -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
        "https://api.cloudflare.com/client/v4/zones/${zid}/dns_records?type=TXT&name=${FQDN}" \
        | grep -o '"id":"[^"]*"' | sed 's/"id":"//;s/"//'); do
        curl -sS -X DELETE -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
          "https://api.cloudflare.com/client/v4/zones/${zid}/dns_records/${id}" >/dev/null || true
      done
      ;;
    hetzner)
      curl -sS -X DELETE -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" \
        "https://api.hetzner.cloud/v1/zones/${DPLY_ACME_ZONE}/rrsets/${RECORD_NAME}/TXT" >/dev/null || true
      ;;
  esac
}

create_txt() {
  case "$DPLY_ACME_PROVIDER" in
    digitalocean)
      curl -sS -X POST -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" -H "Content-Type: application/json" \
        -d "{\"type\":\"TXT\",\"name\":\"${RECORD_NAME}\",\"data\":\"${VALUE}\",\"ttl\":30}" \
        "https://api.digitalocean.com/v2/domains/${DPLY_ACME_ZONE}/records" >/dev/null
      ;;
    cloudflare)
      zid="$(cf_zone_id)"
      [ -z "$zid" ] && { echo "cloudflare: zone ${DPLY_ACME_ZONE} not found" >&2; exit 1; }
      curl -sS -X POST -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" -H "Content-Type: application/json" \
        -d "{\"type\":\"TXT\",\"name\":\"${FQDN}\",\"content\":\"${VALUE}\",\"ttl\":60}" \
        "https://api.cloudflare.com/client/v4/zones/${zid}/dns_records" >/dev/null
      ;;
    hetzner)
      curl -sS -X POST -H "Authorization: Bearer ${DPLY_ACME_TOKEN}" -H "Content-Type: application/json" \
        -d "{\"name\":\"${RECORD_NAME}\",\"type\":\"TXT\",\"ttl\":60,\"records\":[{\"value\":\"\\\"${VALUE}\\\"\"}]}" \
        "https://api.hetzner.cloud/v1/zones/${DPLY_ACME_ZONE}/rrsets" >/dev/null
      ;;
  esac
}

wait_for_propagation() {
  if ! command -v dig >/dev/null 2>&1; then sleep 45; return 0; fi
  ns_list="$(dig +short NS "$DPLY_ACME_ZONE" 2>/dev/null)"
  [ -z "$ns_list" ] && ns_list="1.1.1.1 8.8.8.8"
  for _ in $(seq 1 30); do
    all_seen=1
    for ns in $ns_list; do
      if ! dig +short TXT "$FQDN" "@${ns}" 2>/dev/null | tr -d '"' | grep -qF "$VALUE"; then
        all_seen=0
      fi
    done
    [ "$all_seen" = "1" ] && { sleep 5; return 0; }
    sleep 5
  done
  return 0
}
BASH;

        $body = str_replace('__CREDS_PATH__', $credsPath, $body);

        $action = $isAuth
            ? "clear_txt\ncreate_txt\nwait_for_propagation\n"
            : "clear_txt\n";

        return $body."\n\n".$action;
    }

    private function putRemote(SshConnection $ssh, Server $server, string $path, string $contents, string $mode): void
    {
        $tmp = '/tmp/'.basename($path).'.'.Str::random(8);
        $ssh->putFile($tmp, $contents);

        $cmd = sprintf(
            'sudo -n mv %1$s %2$s && sudo -n chown root:root %2$s && sudo -n chmod %3$s %2$s',
            escapeshellarg($tmp),
            escapeshellarg($path),
            $mode,
        );
        $ssh->exec($cmd.' 2>&1', 30);

        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException("Failed to stage {$path} on the server (exit {$exit}).");
        }
    }
}
