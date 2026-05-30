<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteCertificate;

/**
 * Resolve Let's Encrypt material paths for OpenLiteSpeed vhssl blocks.
 * Certbot stores certs under /etc/letsencrypt/live/{first-domain}/ regardless
 * of which webserver issued them — switching engines keeps the same paths.
 */
final class OpenLiteSpeedTlsPaths
{
    /**
     * True when a Caddy :443 TLS front can reference on-disk Let's Encrypt material.
     */
    public static function siteEdgeTlsFrontReady(Site $site): bool
    {
        return SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('status', SiteCertificate::STATUS_ACTIVE)
            ->whereNotNull('last_installed_at')
            ->exists();
    }

    /**
     * @return array{keyFile: string, certFile: string}|null
     */
    public static function resolve(Site $site): ?array
    {
        if (! self::siteExpectsTls($site)) {
            return null;
        }

        $domain = self::letsEncryptDirectoryName($site);
        if ($domain === null) {
            return null;
        }

        return [
            'keyFile' => '/etc/letsencrypt/live/'.$domain.'/privkey.pem',
            'certFile' => '/etc/letsencrypt/live/'.$domain.'/fullchain.pem',
        ];
    }

    public static function siteExpectsTls(Site $site): bool
    {
        if ($site->ssl_status === Site::SSL_ACTIVE || $site->ssl_status === Site::SSL_PENDING) {
            return true;
        }

        $site->loadMissing(['previewDomains']);

        if ($site->previewDomains->isNotEmpty()) {
            return true;
        }

        return SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->whereIn('status', [
                SiteCertificate::STATUS_ACTIVE,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_FAILED,
            ])
            ->exists();
    }

    /**
     * @return array{keyFile: string, certFile: string}
     */
    public static function vhsslBlock(array $paths): string
    {
        return <<<CONF
vhssl  {
  keyFile                 {$paths['keyFile']}
  certFile                {$paths['certFile']}
  certChain               1
}
CONF;
    }

    private static function letsEncryptDirectoryName(Site $site): ?string
    {
        $cert = SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->whereIn('status', [
                SiteCertificate::STATUS_ACTIVE,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_FAILED,
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'issued' THEN 1 ELSE 2 END")
            ->orderByDesc('last_installed_at')
            ->first();

        $domains = $cert?->domainHostnames() ?? [];
        if ($domains !== []) {
            return $domains[0];
        }

        $site->loadMissing(['previewDomains', 'domains']);

        $preview = $site->previewDomains->firstWhere('is_primary', true)?->hostname
            ?? $site->previewDomains->first()?->hostname;
        if (is_string($preview) && trim($preview) !== '') {
            return strtolower(trim($preview));
        }

        $primary = $site->domains->firstWhere('is_primary', true)?->hostname
            ?? $site->domains->first()?->hostname;
        if (is_string($primary) && trim($primary) !== '') {
            return strtolower(trim($primary));
        }

        return null;
    }
}
