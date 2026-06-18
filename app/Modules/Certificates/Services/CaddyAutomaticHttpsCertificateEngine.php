<?php

namespace App\Modules\Certificates\Services;

use App\Models\Site;
use App\Models\SiteCertificate;

/**
 * Caddy terminates TLS with its built-in automatic HTTPS — it obtains and
 * renews Let's Encrypt certificates itself the first time a hostname is served,
 * so certbot is neither installed nor needed on Caddy boxes (e.g. worker
 * servers). This engine intercepts Let's Encrypt HTTP-01 requests for sites
 * fronted by Caddy and records the certificate as managed-by-Caddy instead of
 * shelling out to certbot (which would fail with "certbot: command not found").
 *
 * Edge-proxy layouts (Envoy/HAProxy/Traefik/OpenResty in front of a Caddy
 * backend) terminate TLS at the front via the certbot webroot challenge, so
 * those fall through to {@see LetsEncryptHttpCertificateEngine}.
 */
class CaddyAutomaticHttpsCertificateEngine implements CertificateEngine
{
    public function supports(SiteCertificate $certificate): bool
    {
        if ($certificate->provider_type !== SiteCertificate::PROVIDER_LETSENCRYPT
            || $certificate->challenge_type !== SiteCertificate::CHALLENGE_HTTP) {
            return false;
        }

        $site = $certificate->site;
        if (! $site instanceof Site) {
            return false;
        }

        return $site->webserver() === 'caddy'
            && ! $site->server?->hasEdgeProxy();
    }

    public function execute(SiteCertificate $certificate): SiteCertificate
    {
        $site = $certificate->site()->with('server')->firstOrFail();
        $certificate->loadMissing('previewDomain');

        $domains = $certificate->domainHostnames();
        if ($domains === []) {
            throw new \InvalidArgumentException('Add at least one domain before requesting SSL.');
        }

        $output = sprintf(
            "[ssl] %s is served by Caddy — TLS is managed by Caddy's built-in automatic HTTPS.\n".
            "[ssl] Caddy obtains and renews the Let's Encrypt certificate on demand; certbot is not used.\n".
            '[ssl] No action required — certificate marked active for %s.',
            $site->name,
            implode(', ', $domains),
        );

        $certificate->forceFill([
            'status' => SiteCertificate::STATUS_ACTIVE,
            'last_requested_at' => now(),
            'last_installed_at' => now(),
            'last_output' => $output,
            'applied_settings' => array_merge($certificate->applied_settings ?? [], [
                'domains' => $domains,
                'managed_by' => 'caddy',
            ]),
        ])->save();

        $site->update([
            'ssl_status' => Site::SSL_ACTIVE,
            'ssl_installed_at' => now(),
            'meta' => array_merge($site->meta ?? [], [
                'ssl_last_output' => $output,
                'ssl_last_attempt_at' => now()->toIso8601String(),
                'ssl_last_requested_domains' => $domains,
            ]),
        ]);

        if ($certificate->scope_type === SiteCertificate::SCOPE_PREVIEW && $certificate->previewDomain) {
            $certificate->previewDomain->update([
                'ssl_status' => 'active',
                'last_ssl_checked_at' => now(),
            ]);
        }

        return $certificate->fresh();
    }
}
