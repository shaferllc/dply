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
     * True when a :443 TLS block can reference on-disk Let's Encrypt material —
     * either a per-site certificate (custom domains) or an installed per-server
     * wildcard covering this site's managed testing hostname (e.g. *.on-dply.com).
     */
    public static function siteEdgeTlsFrontReady(Site $site): bool
    {
        if ($site->coveringServerWildcard() !== null) {
            return true;
        }

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
     * @param  array{keyFile: string, certFile: string}  $paths
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
        $wildcard = $site->coveringServerWildcard();

        // A wildcard-covered, testing-only site (no custom primary domain)
        // resolves to /etc/letsencrypt/live/<zone>/ — the shared wildcard — so a
        // stale per-site preview cert never shadows it.
        if ($wildcard !== null && self::primaryHostnameIsTestingPreview($site)) {
            $dir = strtolower(trim((string) ($wildcard->live_directory ?: $site->testingZone())));
            if ($dir !== '') {
                return $dir;
            }
        }

        // A PREVIEW-scoped cert covers a single preview hostname, so it must never
        // become the shared ssl_certificate for a multi-host server block — doing
        // so breaks every OTHER hostname on the block (the exact failure where a
        // stray per-preview cert shadowed the customer domain + sibling previews).
        // Preview hosts are secured by the wildcard instead.
        // Likewise a PER-TENANT cert (source = tenant_ssl) covers one tenant
        // hostname, so it must never become the block cert either — otherwise a
        // tenant's cert shadows the customer domain + the *.zone testing hosts
        // (the regression where app.<domain> broke every on-dply.com host).
        $cert = SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->where('scope_type', '!=', SiteCertificate::SCOPE_PREVIEW)
            ->whereIn('status', [
                SiteCertificate::STATUS_ACTIVE,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_FAILED,
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'issued' THEN 1 ELSE 2 END")
            ->orderByDesc('last_installed_at')
            ->get()
            ->first(function (SiteCertificate $candidate): bool {
                $source = is_array($candidate->requested_settings) ? ($candidate->requested_settings['source'] ?? null) : null;

                return $source !== 'tenant_ssl';
            });

        $domains = $cert?->domainHostnames() ?? [];
        if ($domains !== []) {
            return $domains[0];
        }

        // No customer/non-preview cert: when the testing hostnames are wildcard-
        // covered, that shared cert secures the block (it covers every *.zone
        // preview host), so use it rather than a per-host preview path.
        if ($wildcard !== null) {
            $dir = strtolower(trim((string) ($wildcard->live_directory ?: $site->testingZone())));
            if ($dir !== '') {
                return $dir;
            }
        }

        $site->loadMissing(['previewDomains', 'domains']);

        $preview = $site->previewDomains->firstWhere('is_primary', true)->hostname
            ?? $site->previewDomains->first()?->hostname;
        if (is_string($preview) && trim($preview) !== '') {
            return strtolower(trim($preview));
        }

        $primary = $site->domains->firstWhere('is_primary', true)->hostname
            ?? $site->domains->first()?->hostname;
        if (is_string($primary) && trim($primary) !== '') {
            return strtolower(trim($primary));
        }

        // Last resort: a covering wildcard (e.g. preview is the only hostname
        // and the heuristic above didn't classify it as testing-preview).
        if ($wildcard !== null) {
            $dir = strtolower(trim((string) ($wildcard->live_directory ?: $site->testingZone())));
            if ($dir !== '') {
                return $dir;
            }
        }

        return null;
    }

    /**
     * A testing-only site — served on the managed testing hostname with no
     * customer/custom primary domain — so its TLS should come from the shared
     * per-server wildcard rather than a per-host certificate.
     */
    private static function primaryHostnameIsTestingPreview(Site $site): bool
    {
        return $site->primaryPreviewDomain() !== null
            && $site->customerDomainHostnames() === [];
    }
}
