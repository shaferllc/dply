<?php

declare(strict_types=1);

namespace App\Services\Cloud\Concerns;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\DigitalOceanAppPlatformService;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoAppDomainsEnv
{


    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [];
        }
        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = is_array($current['spec'] ?? null) ? $current['spec'] : [];
        $service->attachDomain($site->container_backend_id, $spec, $hostname);

        return [];
    }

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }
        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = is_array($current['spec'] ?? null) ? $current['spec'] : [];
        $service->detachDomain($site->container_backend_id, $spec, $hostname);
    }

    /**
     * @return array<string, string>
     */
    private function siteEnvVars(Site $site): array
    {
        return $this->parseEnvLines((string) ($site->env_file_content ?? ''));
    }

    /**
     * Build-time env vars are stored separately on the Site's meta
     * under meta.container.build_env_file_content (same .env format).
     * They map to DO scope=BUILD_TIME / App Runner BuildEnvironmentVariables —
     * needed for app secrets (e.g. private package tokens) that the
     * build step requires but shouldn't leak into runtime.
     *
     * @return array<string, string>
     */
    private function siteBuildEnvVars(Site $site): array
    {
        $meta = ($site->meta );
        $content = $meta['container']['build_env_file_content'] ?? '';

        return $this->parseEnvLines(is_string($content) ? $content : '');
    }

    /**
     * Desired instance count for the site. Operators set this via
     * dply:cloud:scale; defaults to 1 when not configured.
     */
    private function siteInstanceCount(Site $site): int
    {
        $meta = ($site->meta );
        $raw = $meta['container']['instance_count'] ?? null;

        return is_int($raw) && $raw > 0 ? $raw : 1;
    }

    /**
     * Map the site's portable size_tier to DO App Platform's
     * instance_size_slug. Basic tiers map to `basic-*` slugs (the
     * default, cheapest path). The Pro variants (`*-pro`) map to
     * `apps-d-*` Professional slugs and are required when CPU
     * autoscaling is enabled — Basic tier rejects autoscaling at
     * spec-validation time on DO's side. Operators opt into Pro
     * deliberately via the size selector or `dply:cloud:resize`;
     * we never auto-upgrade behind their back because the cost
     * delta is significant.
     */
    private function siteSizeSlugForDo(Site $site): string
    {
        $meta = ($site->meta );
        $tier = (string) ($meta['container']['size_tier'] ?? 'small');

        return match ($tier) {
            'medium' => 'basic-xs',
            'large' => 'basic-s',
            'xlarge' => 'basic-m',
            'small-pro' => 'apps-d-1vcpu-0.5gb',
            'medium-pro' => 'apps-d-1vcpu-1gb',
            'large-pro' => 'apps-d-1vcpu-2gb',
            'xlarge-pro' => 'apps-d-2vcpu-4gb',
            default => 'basic-xxs',
        };
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvLines(string $envContent): array
    {
        if ($envContent === '') {
            return [];
        }
        $vars = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * Split a Docker image ref into [registry_host, repository, tag] —
     * the same parsing DigitalOceanAppPlatformService uses, duplicated
     * here so worker spec building does not need a credential-bound
     * service instance just to parse a string.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseImageRef(string $image): array
    {
        $tag = 'latest';
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($image, $lastColon + 1);
            $image = substr($image, 0, $lastColon);
        }

        $parts = explode('/', $image);
        $registry = 'docker.io';
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':'))) {
            $registry = array_shift($parts);
        }
        $repository = implode('/', $parts);
        if ($registry === 'docker.io' && ! str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }

        return [$registry, $repository, $tag];
    }

    private function backendAppName(Site $site): string
    {
        // DO App Platform names: lowercase, alnum + hyphen, ≤ 32 chars.
        $name = preg_replace('/[^a-z0-9-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-');

        return substr($name, 0, 32) ?: 'dply-app';
    }
}
