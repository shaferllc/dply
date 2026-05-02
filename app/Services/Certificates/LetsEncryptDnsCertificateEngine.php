<?php

namespace App\Services\Certificates;

use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\SshConnection;

class LetsEncryptDnsCertificateEngine implements CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool
    {
        return $certificate->provider_type === SiteCertificate::PROVIDER_LETSENCRYPT
            && $certificate->challenge_type === SiteCertificate::CHALLENGE_DNS;
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with(['server', 'user', 'organization'])->firstOrFail();
        $server = $site->server;

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('Add at least one domain before requesting DNS challenge SSL.');
        }

        $credential = $certificate->providerCredential;
        $provider = (string) ($certificate->dns_provider ?: $credential?->provider ?: '');
        $token = $credential?->getApiToken();

        if ($provider === '' || ! in_array($provider, ['digitalocean'], true)) {
            throw new \RuntimeException('DNS challenge is only implemented for supported providers.');
        }

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('A provider credential with an API token is required for DNS challenge issuance.');
        }

        $email = config('sites.certbot_email')
            ?: $site->user?->email
            ?: $site->organization?->email;

        if (! is_string($email) || $email === '') {
            throw new \InvalidArgumentException('Set DPLY_CERTBOT_EMAIL, organization email, or user email for Let\'s Encrypt.');
        }

        $credentialsPath = sprintf('/tmp/dply-certbot-%s.ini', $certificate->id);
        $pluginCommand = match ($provider) {
            'digitalocean' => sprintf(
                'printf %s > %s && chmod 600 %s && certbot certonly --dns-digitalocean --dns-digitalocean-credentials %s %s --non-interactive --agree-tos -m %s 2>&1',
                escapeshellarg("dns_digitalocean_token = {$token}\n"),
                escapeshellarg($credentialsPath),
                escapeshellarg($credentialsPath),
                escapeshellarg($credentialsPath),
                collect($domains)->map(fn (string $domain): string => '-d '.escapeshellarg($domain))->implode(' '),
                escapeshellarg($email)
            ),
            default => throw new \RuntimeException('Unsupported DNS provider.'),
        };

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_PENDING,
            'last_requested_at' => now(),
        ])->save();

        $ssh = new SshConnection($server);
        $output = $ssh->exec($pluginCommand.'; status=$?; rm -f '.escapeshellarg($credentialsPath).'; printf "\nDPLY_EXIT:%s" "$status"', 600);
        $exitCode = preg_match('/DPLY_EXIT:(\d+)/', $output, $matches) ? (int) $matches[1] : 1;
        $ok = $exitCode === 0;

        $certificate->forceFill([
            'status' => $ok ? SiteCertificate::STATUS_ACTIVE : SiteCertificate::STATUS_FAILED,
            'last_output' => $output,
            'last_installed_at' => $ok ? now() : $certificate->last_installed_at,
            'applied_settings' => array_merge($certificate->applied_settings ?? [], [
                'dns_provider' => $provider,
                'http3_requested' => (bool) $certificate->enable_http3,
                'http3_applied' => false,
            ]),
        ])->save();

        $site->update([
            'ssl_status' => $ok ? Site::SSL_ACTIVE : Site::SSL_FAILED,
            'ssl_installed_at' => $ok ? now() : $site->ssl_installed_at,
        ]);

        if (! $ok) {
            throw new \RuntimeException('DNS challenge certificate request failed.');
        }

        return $certificate->fresh();
    }
}
