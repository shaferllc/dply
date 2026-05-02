<?php

namespace App\Services\Certificates;

use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\SshConnection;

class LetsEncryptHttpCertificateEngine implements CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_LETSENCRYPT
            && $certificate->challenge_type === SiteCertificate::CHALLENGE_HTTP;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with(['server', 'domains', 'previewDomains', 'user', 'organization'])->firstOrFail();
        $server = $site->server;

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('Add at least one domain before requesting SSL.');
        }

        $email = config('sites.certbot_email')
            ?: $site->user?->email
            ?: $site->organization?->email;

        if (! is_string($email) || $email === '') {
            throw new \InvalidArgumentException('Set DPLY_CERTBOT_EMAIL, organization email, or user email for Let\'s Encrypt.');
        }

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_PENDING,
            'last_requested_at' => now(),
            'requested_settings' => array_merge($certificate->requested_settings ?? [], [
                'email' => $email,
            ]),
        ])->save();

        $flags = collect($domains)->map(fn (string $domain): string => '-d '.escapeshellarg($domain))->implode(' ');
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
        $output = $ssh->exec($cmd.'; printf "\nDPLY_EXIT:%s" "$?"', 600);
        $exitCode = preg_match('/DPLY_EXIT:(\d+)/', $output, $matches) ? (int) $matches[1] : 1;
        $ok = $exitCode === 0;

        $certificate->forceFill([
            'status' => $ok ? SiteCertificate::STATUS_ACTIVE : SiteCertificate::STATUS_FAILED,
            'last_output' => $output,
            'last_installed_at' => $ok ? now() : $certificate->last_installed_at,
            'applied_settings' => array_merge($certificate->applied_settings ?? [], [
                'domains' => $domains,
                'http3_requested' => (bool) $certificate->enable_http3,
                'http3_applied' => false,
            ]),
        ])->save();

        $site->update([
            'ssl_status' => $ok ? Site::SSL_ACTIVE : Site::SSL_FAILED,
            'ssl_installed_at' => $ok ? now() : $site->ssl_installed_at,
            'meta' => array_merge($site->meta ?? [], [
                'ssl_last_output' => $output,
                'ssl_last_attempt_at' => now()->toIso8601String(),
                'ssl_last_requested_domains' => $domains,
            ]),
        ]);

        if ($certificate->scope_type === SiteCertificate::SCOPE_PREVIEW && $certificate->previewDomain) {
            $certificate->previewDomain->update([
                'ssl_status' => $ok ? 'active' : 'failed',
                'last_ssl_checked_at' => now(),
            ]);
        }

        if (! $ok) {
            throw new \RuntimeException('Certbot exited with code '.$exitCode.'. Check certificate output for details.');
        }

        return $certificate->fresh();
    }
}
