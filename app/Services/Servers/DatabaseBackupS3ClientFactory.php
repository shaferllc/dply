<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\BackupConfiguration;
use Aws\S3\S3Client;
use InvalidArgumentException;

/**
 * Build an S3 client + bucket metadata from an org {@see BackupConfiguration}.
 * v1 supports S3-compatible providers only (AWS, custom, DO Spaces).
 */
final class DatabaseBackupS3ClientFactory
{
    /**
     * @return array{client: S3Client, bucket: string, key_prefix: string}
     */
    public function forConfiguration(BackupConfiguration $configuration): array
    {
        $config = $configuration->config ?? [];
        if (! is_array($config)) {
            throw new InvalidArgumentException('Backup destination config is invalid.');
        }

        return match ($configuration->provider) {
            BackupConfiguration::PROVIDER_AWS_S3,
            BackupConfiguration::PROVIDER_CUSTOM_S3,
            BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES => $this->buildS3Compatible(
                $config,
                $configuration->provider === BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES,
            ),
            default => throw new InvalidArgumentException(
                __('Database backups currently support S3-compatible destinations only. Pick AWS S3, Custom S3, or DigitalOcean Spaces.')
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{client: S3Client, bucket: string, key_prefix: string}
     */
    private function buildS3Compatible(array $config, bool $isSpaces): array
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKey = trim((string) ($config['access_key'] ?? ''));
        $secret = trim((string) ($config['secret'] ?? ''));

        if ($bucket === '' || $accessKey === '' || $secret === '') {
            throw new InvalidArgumentException('S3 destination is missing bucket or credentials.');
        }

        $region = trim((string) ($config['region'] ?? ''));
        if ($region === '') {
            $region = $isSpaces ? 'us-east-1' : 'us-east-1';
        }

        $args = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secret,
            ],
        ];

        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($isSpaces && $endpoint === '') {
            $endpoint = 'https://'.$region.'.digitaloceanspaces.com';
        }

        if ($endpoint !== '') {
            $args['endpoint'] = $endpoint;
        }

        if ((bool) ($config['use_path_style'] ?? false)) {
            $args['use_path_style_endpoint'] = true;
        }

        $prefix = trim((string) ($config['path'] ?? ''), '/');

        return [
            'client' => new S3Client($args),
            'bucket' => $bucket,
            'key_prefix' => $prefix,
        ];
    }
}
