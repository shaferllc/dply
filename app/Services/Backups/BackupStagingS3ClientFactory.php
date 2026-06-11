<?php

declare(strict_types=1);

namespace App\Services\Backups;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * Builds the S3 client for the global, operator-managed Hetzner download-staging
 * bucket from config/backup_staging.php. Mirrors DatabaseBackupS3ClientFactory /
 * ObjectStorageVaultStore so the S3 wiring is identical, but reads a single
 * env-driven config rather than a per-org BackupConfiguration.
 */
final class BackupStagingS3ClientFactory
{
    /**
     * True only when the staging bucket is fully configured and enabled.
     */
    public function enabled(): bool
    {
        $c = (array) config('backup_staging.hetzner', []);

        return (bool) ($c['enabled'] ?? false)
            && filled($c['bucket'] ?? null)
            && filled($c['access_key'] ?? null)
            && filled($c['secret'] ?? null);
    }

    /**
     * @return array{client: S3Client, bucket: string, key_prefix: string}
     */
    public function make(): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException(__('The download-staging bucket is not configured.'));
        }

        $c = (array) config('backup_staging.hetzner', []);

        $region = trim((string) ($c['region'] ?? '')) ?: 'fsn1';

        $args = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => (string) $c['access_key'],
                'secret' => (string) $c['secret'],
            ],
        ];

        $args['endpoint'] = $this->resolveEndpoint($c, $region);

        if ((bool) ($c['use_path_style'] ?? false)) {
            $args['use_path_style_endpoint'] = true;
        }

        return [
            'client' => new S3Client($args),
            'bucket' => trim((string) $c['bucket']),
            'key_prefix' => trim((string) ($c['path'] ?? ''), '/'),
        ];
    }

    /**
     * Explicit endpoint override, else the Hetzner provider template with {region}
     * substituted (config/object_storage.php → providers.hetzner.endpoint_template).
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveEndpoint(array $config, string $region): string
    {
        $explicit = trim((string) ($config['endpoint'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $template = (string) config('object_storage.providers.hetzner.endpoint_template', 'https://{region}.your-objectstorage.com');

        return str_replace('{region}', $region, $template);
    }
}
