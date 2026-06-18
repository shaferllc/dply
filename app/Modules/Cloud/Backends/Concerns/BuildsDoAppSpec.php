<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends\Concerns;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsDoAppSpec
{


    /**
     * Resolve `username:token` for the registry credential attached to
     * the site (if any), so DO can pull private images at deploy time.
     *
     * Only GHCR currently flows through here. DOCR uses the app's DO
     * PAT transparently (no separate creds needed) and Docker Hub
     * private repos can be added the same way as GHCR when we ship
     * that credential type.
     */
    public static function imageRegistryCredentialsFor(Site $site): ?string
    {
        $meta = ($site->meta );
        $credId = $meta['container']['image_credential_id'] ?? null;
        if (! is_string($credId) || $credId === '') {
            return null;
        }

        $cred = ProviderCredential::query()->find($credId);
        if ($cred === null || $cred->organization_id !== $site->organization_id) {
            return null;
        }

        $body = ($cred->credentials );
        $username = (string) ($body['username'] ?? '');
        $token = (string) ($body['token'] ?? $body['api_token'] ?? '');
        if ($username === '' || $token === '') {
            return null;
        }

        return $username.':'.$token;
    }

    /**
     * Build a minimal spec body from a payload (NOT a saved Site) for
     * pre-submit validation via /apps/propose. Mirrors the shape
     * createApp/createAppFromSource emit; intentionally skips workers/
     * jobs/alerts since those don't affect propose's cost-or-error
     * outcome materially and would require persisted child rows.
     *
     * The form calls this with its own state to get a cost estimate
     * and to catch spec validation errors before the user submits.
     *
     * @param  array{name: string, region: string, size_tier_slug: string, instances: int, port: int, mode: string, image?: string, repo?: string, branch?: string, dockerfile_path?: ?string, autoscaling?: ?array<string, mixed>, health_check?: ?array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    public static function buildProposeSpecFromPayload(array $payload): array
    {
        $service = [
            'name' => 'web',
            'http_port' => (int) $payload['port'],
            'instance_size_slug' => (string) $payload['size_tier_slug'],
        ];

        if (($payload['mode'] ?? 'image') === 'source') {
            $service['github'] = [
                'repo' => (string) ($payload['repo'] ?? ''),
                'branch' => (string) ($payload['branch'] ?? 'main'),
                'deploy_on_push' => true,
            ];
            $dockerfile = $payload['dockerfile_path'] ?? null;
            if (is_string($dockerfile) && $dockerfile !== '') {
                $service['dockerfile_path'] = $dockerfile;
            }
        } else {
            $service['image'] = DigitalOceanAppPlatformService::imageSpecBlock((string) ($payload['image'] ?? ''));
        }

        $autoscaling = $payload['autoscaling'] ?? null;
        if (is_array($autoscaling) && ($autoscaling['enabled'] ?? false)) {
            $service['autoscaling'] = [
                'min_instance_count' => (int) ($autoscaling['min_instances'] ?? 1),
                'max_instance_count' => (int) ($autoscaling['max_instances'] ?? 3),
                'metrics' => [
                    'cpu' => ['percent' => (int) ($autoscaling['cpu_percent'] ?? 75)],
                ],
            ];
        } else {
            $service['instance_count'] = max(1, (int) $payload['instances']);
        }

        $health = $payload['health_check'] ?? null;
        if (is_array($health) && ($health['enabled'] ?? false)) {
            $service['health_check'] = [
                'http_path' => (string) ($health['http_path'] ?? '/'),
                'period_seconds' => (int) ($health['period_seconds'] ?? 30),
                'timeout_seconds' => (int) ($health['timeout_seconds'] ?? 5),
                'failure_threshold' => (int) ($health['failure_threshold'] ?? 3),
            ];
        }

        return [
            'name' => (string) $payload['name'] ?: 'dply-propose-probe',
            'region' => (string) $payload['region'] ?: 'nyc',
            'services' => [$service],
        ];
    }
}
