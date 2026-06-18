<?php

declare(strict_types=1);

namespace App\Modules\Snapshots\Services;

use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Aws\S3\S3Client;

/**
 * Resolves the appropriate {@see SnapshotDestination} at call time.
 *
 * Callers ask for a "preferred" destination (local for transient
 * safety-net, s3 for archive); the factory falls back to local when
 * S3 isn't configured so the safety-net flow always works regardless
 * of whether the operator has set up off-site backups yet.
 */
class SnapshotDestinationFactory
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Always-available transient destination (Q19 safety net).
     */
    public function localDisk(): LocalDiskDestination
    {
        return new LocalDiskDestination($this->executor);
    }

    /**
     * Configured archive destination, or null when no S3 bucket is
     * configured. Callers that prefer S3 but want a safe fallback can
     * write `$factory->s3() ?? $factory->localDisk()`.
     */
    public function s3(): ?S3Destination
    {
        if (! config('snapshot_s3.enabled')) {
            return null;
        }

        $bucket = (string) config('snapshot_s3.bucket', '');
        if ($bucket === '') {
            return null;
        }

        $args = [
            'version' => 'latest',
            'region' => (string) config('snapshot_s3.region', 'us-east-1'),
        ];

        $endpoint = config('snapshot_s3.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $args['endpoint'] = $endpoint;
        }

        if (config('snapshot_s3.use_path_style_endpoint')) {
            $args['use_path_style_endpoint'] = true;
        }

        $key = config('snapshot_s3.key');
        $secret = config('snapshot_s3.secret');
        if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
            $args['credentials'] = ['key' => $key, 'secret' => $secret];
        }
        // No credentials block → SDK falls back to the AWS default
        // chain (env vars, IAM roles, ~/.aws/credentials).

        return new S3Destination(
            executor: $this->executor,
            s3: new S3Client($args),
            bucket: $bucket,
            keyPrefix: (string) config('snapshot_s3.key_prefix', ''),
        );
    }

    /**
     * Preferred destination: S3 when configured, otherwise local.
     * Use this for "take a backup" actions where the operator's intent
     * is durable storage.
     */
    public function preferred(): SnapshotDestination
    {
        return $this->s3() ?? $this->localDisk();
    }
}
