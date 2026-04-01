<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

class SiteSslProvisioner
{
    public function provision(Site $site, ?string $email = null): string
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $site->loadMissing('domains');
        $domains = $site->sslDomainHostnames();
        if ($domains->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before requesting SSL.');
        }

        $email = $email
            ?: config('sites.certbot_email')
            ?: $site->user?->email
            ?: $server->organization?->email;

        if (empty($email)) {
            throw new \InvalidArgumentException('Set DPLY_CERTBOT_EMAIL, organization email, or user email for Let\'s Encrypt.');
        }

        $site->update(['ssl_status' => Site::SSL_PENDING]);

        $flags = $domains->map(fn (string $d) => '-d '.escapeshellarg($d))->implode(' ');
        $cmd = match ($site->webserver()) {
            'apache' => sprintf(
                'certbot --apache %s --non-interactive --agree-tos -m %s --redirect 2>&1',
                $flags,
                escapeshellarg($email)
            ),
            'openlitespeed', 'traefik', 'caddy' => sprintf(
                'certbot certonly --webroot -w %s %s --non-interactive --agree-tos -m %s 2>&1',
                escapeshellarg($site->effectiveDocumentRoot()),
                $flags,
                escapeshellarg($email)
            ),
            default => sprintf(
                'certbot --nginx %s --non-interactive --agree-tos -m %s --redirect 2>&1',
                $flags,
                escapeshellarg($email)
            ),
        };

        $ssh = new SshConnection($server);
        $out = $ssh->exec($cmd.'; printf "\nDPLY_EXIT:%s" "$?"', 600);
        $code = 1;
        if (preg_match('/DPLY_EXIT:(\d+)/', $out, $m)) {
            $code = (int) $m[1];
        }

        $ok = $code === 0;

        $site->update([
            'ssl_status' => $ok ? Site::SSL_ACTIVE : Site::SSL_FAILED,
            'ssl_installed_at' => $ok ? now() : $site->ssl_installed_at,
            'meta' => array_merge($site->meta ?? [], [
                'ssl_last_output' => $out,
                'ssl_last_attempt_at' => now()->toIso8601String(),
                'ssl_last_requested_domains' => $domains->all(),
            ]),
        ]);

        if (! $ok) {
            throw new \RuntimeException('Certbot exited with code '.$code.'. Check ssl_last_output in site meta or logs.');
        }

        return $out;
    }
}
