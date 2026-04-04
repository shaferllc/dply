<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\TraefikSiteConfigBuilder;

class TraefikWebserverConfigEngine implements WebserverConfigEngineInterface
{
    public function __construct(
        private readonly TraefikSiteConfigBuilder $builder,
    ) {}

    public function webserver(): string
    {
        return 'traefik';
    }

    public function effectiveConfig(Site $site, ?SiteWebserverConfigProfile $profile): string
    {
        if ($profile && $profile->isFullOverride() && trim((string) $profile->full_override_body) !== '') {
            return trim((string) $profile->full_override_body);
        }

        return $this->builder->build($site, $this->backendPort($site));
    }

    public function managedCoreHash(Site $site): string
    {
        return hash('sha256', $this->builder->build($site, $this->backendPort($site)));
    }

    private function backendPort(Site $site): int
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $existing = $meta['traefik_backend_port'] ?? null;
        if (is_numeric($existing) && (int) $existing >= 20000) {
            return (int) $existing;
        }

        return 20000 + (abs(crc32((string) $site->getKey())) % 20000);
    }

    public function validateLocal(string $config): array
    {
        return [
            'ok' => true,
            'message' => __('Traefik uses multiple files on the server. Preview shows the dynamic YAML; use Apply to validate end-to-end.'),
        ];
    }

    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array
    {
        return [
            'ok' => true,
            'message' => __('Remote dry-run for Traefik is not available. Apply runs Caddy and Traefik reloads with validation.'),
        ];
    }
}
