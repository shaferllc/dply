<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates a bucket on an S3-compatible object storage provider (DigitalOcean
 * Spaces, Hetzner Object Storage) using the operator's storage access keys.
 *
 * Provider bucket creation is a single CreateBucket API call — fast enough to
 * run inline from the binding flow, the same way database provisioning calls
 * the server provisioner synchronously. Only providers flagged `provision` in
 * config/object_storage.php are accepted; the endpoint is derived from that
 * provider's template and the chosen region.
 */
// Not final: the serverless app-bucket flow resolves this from the container
// and its tests substitute a double for it, so provisioning can be exercised
// without a provider account.
class ObjectStorageBucketProvisioner
{
    /**
     * Create $bucket on $provider in $region with the given S3 keys. Idempotent
     * when the operator already owns a bucket by that name. Returns the
     * resolved S3 endpoint so the caller can wire AWS_ENDPOINT.
     *
     * $awaitKeyPropagation: set when the keys were just minted via a provider
     * API (DigitalOcean Spaces). A freshly-created key isn't active on the S3
     * gateway for a few seconds, so the first CreateBucket can fail with
     * InvalidAccessKeyId/SignatureDoesNotMatch — we retry those briefly. Leave
     * false for operator-supplied keys so genuinely-wrong keys fail fast.
     *
     * @return array{endpoint: string}
     */
    public function create(string $provider, string $region, string $accessKey, string $secret, string $bucket, bool $awaitKeyPropagation = false): array
    {
        $providers = (array) config('object_storage.providers', []);
        $meta = $providers[$provider] ?? null;
        if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
            throw new InvalidArgumentException(__('This provider does not support provisioning a bucket yet.'));
        }

        $region = trim($region);
        if ($region === '') {
            throw new InvalidArgumentException(__('Choose a region for the new bucket.'));
        }

        $endpoint = $this->endpointFor($meta, $region);
        $providerLabel = (string) ($meta['label'] ?? $provider);

        // Path-style addressing for creation so we don't depend on the
        // per-bucket subdomain resolving the instant the bucket is made.
        $client = $this->client($region, $endpoint, $accessKey, $secret);

        // A just-minted key can take a few seconds to become active on the S3
        // gateway, so retry the rejection codes when the caller knows the keys
        // are fresh. Operator-supplied keys (awaitKeyPropagation=false) get one
        // attempt and fail fast.
        $maxAttempts = $awaitKeyPropagation ? max(1, (int) config('object_storage.fresh_key_retry_attempts', 6)) : 1;
        $delayMicros = max(0, (int) config('object_storage.fresh_key_retry_delay_ms', 2500)) * 1000;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $client->createBucket(['Bucket' => $bucket]);

                return ['endpoint' => $endpoint];
            } catch (S3Exception $e) {
                $code = (string) $e->getAwsErrorCode();

                // The operator already owns this bucket — treat as success so the
                // binding still wires it (lets "provision" double as "adopt mine").
                if ($code === 'BucketAlreadyOwnedByYou') {
                    return ['endpoint' => $endpoint];
                }

                if ($code === 'BucketAlreadyExists') {
                    throw new RuntimeException(__('That bucket name is already taken on this provider — choose another.'));
                }

                // Fresh-key propagation window: the key was just minted and isn't
                // active yet. Retry these before giving up.
                if (in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch'], true) && $attempt < $maxAttempts) {
                    usleep($delayMicros);

                    continue;
                }

                if (in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied'], true)) {
                    throw new RuntimeException(__('The storage keys were rejected by :provider — check the access key and secret.', ['provider' => $providerLabel]));
                }

                throw new RuntimeException(__('Could not create the bucket: :err', ['err' => $e->getAwsErrorMessage() ?: $e->getMessage()]));
            } catch (AwsException $e) {
                throw new RuntimeException(__('Could not reach :provider to create the bucket: :err', ['provider' => $providerLabel, 'err' => $e->getMessage()]));
            }
        }
    }

    /**
     * Empty and delete a provisioned bucket. Idempotent when the bucket is
     * already gone. Used when an operator detaches a storage binding and opts
     * to delete the underlying bucket.
     */
    public function delete(string $provider, string $region, string $accessKey, string $secret, string $bucket, bool $awaitKeyPropagation = false): void
    {
        $providers = (array) config('object_storage.providers', []);
        $meta = $providers[$provider] ?? null;
        if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
            throw new InvalidArgumentException(__('This provider does not support deleting a provisioned bucket.'));
        }

        $region = trim($region);
        if ($region === '') {
            throw new InvalidArgumentException(__('Choose a region for the bucket.'));
        }

        $endpoint = $this->endpointFor($meta, $region);
        $providerLabel = (string) ($meta['label'] ?? $provider);
        $client = $this->client($region, $endpoint, $accessKey, $secret, timeout: 60);

        $maxAttempts = $awaitKeyPropagation ? max(1, (int) config('object_storage.fresh_key_retry_attempts', 6)) : 1;
        $delayMicros = max(0, (int) config('object_storage.fresh_key_retry_delay_ms', 2500)) * 1000;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $paginator = $client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]);
                foreach ($paginator as $page) {
                    $objects = $page['Contents'] ?? [];
                    if ($objects === []) {
                        continue;
                    }

                    $client->deleteObjects([
                        'Bucket' => $bucket,
                        'Delete' => [
                            'Objects' => array_map(
                                fn (array $object): array => ['Key' => (string) $object['Key']],
                                $objects,
                            ),
                        ],
                    ]);
                }

                $client->deleteBucket(['Bucket' => $bucket]);

                return;
            } catch (S3Exception $e) {
                $code = (string) $e->getAwsErrorCode();
                if (in_array($code, ['NoSuchBucket', 'NotFound'], true)) {
                    return;
                }

                // Same fresh-key window as create(): a teardown that mints its
                // own key hits the S3 gateway before the key is active.
                if (in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch'], true) && $attempt < $maxAttempts) {
                    usleep($delayMicros);

                    continue;
                }

                throw new RuntimeException(__('Could not delete the bucket: :err', ['err' => $e->getAwsErrorMessage() ?: $e->getMessage()]));
            } catch (AwsException $e) {
                throw new RuntimeException(__('Could not reach :provider to delete the bucket: :err', ['provider' => $providerLabel, 'err' => $e->getMessage()]));
            }
        }
    }

    /**
     * Make a bucket usable as an app's upload target: browser-reachable CORS,
     * plus a lifecycle policy that clears the staging prefix and abandoned
     * multipart uploads.
     *
     * CORS is what makes browser-direct uploads possible at all — a presigned
     * PUT is a cross-origin request, and without this the browser refuses it
     * before the request leaves. `*` is right for the origin list: the URL
     * carries its own signature and expiry, so the app (not the bucket)
     * decides who may upload, and a fixed origin list would break every
     * preview/custom domain the same app is served from.
     *
     * The `tmp/` expiry is the counterpart: uploads land in a staging prefix
     * the app promotes on success, so anything left there is an upload that
     * was never claimed. One day is the finest granularity S3 lifecycle
     * offers.
     *
     * Best-effort by contract: throws, and the caller decides. A bucket
     * without this policy still works for server-side reads and writes.
     */
    public function applyUploadPolicy(string $provider, string $region, string $accessKey, string $secret, string $bucket, string $tmpPrefix = 'tmp/', int $tmpExpiryDays = 1): void
    {
        $client = $this->clientFor($provider, $region, $accessKey, $secret);

        try {
            $client->putBucketCors([
                'Bucket' => $bucket,
                'CORSConfiguration' => [
                    'CORSRules' => [[
                        'AllowedHeaders' => ['*'],
                        'AllowedMethods' => ['GET', 'HEAD', 'PUT', 'POST', 'DELETE'],
                        'AllowedOrigins' => ['*'],
                        // ETag is how a client confirms what it uploaded, and
                        // it is not exposed to script without this.
                        'ExposeHeaders' => ['ETag', 'Content-Length'],
                        'MaxAgeSeconds' => 3600,
                    ]],
                ],
            ]);

            $client->putBucketLifecycleConfiguration([
                'Bucket' => $bucket,
                'LifecycleConfiguration' => [
                    'Rules' => [
                        [
                            'ID' => 'dply-tmp-expiry',
                            'Status' => 'Enabled',
                            // Rule-level Prefix rather than Filter: it is the
                            // shape S3-compatible providers (Spaces) accept,
                            // and the SDK still models it.
                            'Prefix' => $tmpPrefix,
                            'Expiration' => ['Days' => max(1, $tmpExpiryDays)],
                        ],
                        [
                            'ID' => 'dply-abort-multipart',
                            'Status' => 'Enabled',
                            'Prefix' => '',
                            'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => max(1, $tmpExpiryDays)],
                        ],
                    ],
                ],
            ]);
        } catch (AwsException $e) {
            throw new RuntimeException(__('Could not apply the upload policy: :err', ['err' => $e->getAwsErrorMessage() ?: $e->getMessage()]));
        }
    }

    /**
     * Total bytes stored in a bucket, measured by listing it. Providers that
     * bill for storage expose no cheaper number, and a LIST costs nothing on
     * Spaces, so this is the measurement rather than an estimate.
     */
    public function usageBytes(string $provider, string $region, string $accessKey, string $secret, string $bucket): int
    {
        $client = $this->clientFor($provider, $region, $accessKey, $secret, timeout: 60);

        try {
            $bytes = 0;
            foreach ($client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $bytes += max(0, (int) ($object['Size'] ?? 0));
                }
            }

            return $bytes;
        } catch (S3Exception $e) {
            if (in_array((string) $e->getAwsErrorCode(), ['NoSuchBucket', 'NotFound'], true)) {
                return 0;
            }

            throw new RuntimeException(__('Could not measure the bucket: :err', ['err' => $e->getAwsErrorMessage() ?: $e->getMessage()]));
        } catch (AwsException $e) {
            throw new RuntimeException(__('Could not reach the provider to measure the bucket: :err', ['err' => $e->getMessage()]));
        }
    }

    /**
     * Endpoint from the provider template, or an explanatory failure. Bucket
     * ops all need this, so it lives here rather than in each of them.
     *
     * @param  array<string, mixed>  $meta
     */
    private function endpointFor(array $meta, string $region): string
    {
        $template = (string) ($meta['endpoint_template'] ?? '');
        $endpoint = $template !== '' ? str_replace('{region}', $region, $template) : '';
        if ($endpoint === '') {
            throw new InvalidArgumentException(__('Could not resolve the storage endpoint for this provider and region.'));
        }

        return $endpoint;
    }

    /**
     * Resolve a provisionable provider and build a client for it in one step,
     * for the bucket operations that don't otherwise need the metadata.
     */
    private function clientFor(string $provider, string $region, string $accessKey, string $secret, int $timeout = 20): S3Client
    {
        $providers = (array) config('object_storage.providers', []);
        $meta = $providers[$provider] ?? null;
        if (! is_array($meta)) {
            throw new InvalidArgumentException(__('Unknown storage provider.'));
        }

        $region = trim($region);
        if ($region === '') {
            throw new InvalidArgumentException(__('Choose a region for the bucket.'));
        }

        return $this->client($region, $this->endpointFor($meta, $region), $accessKey, $secret, $timeout);
    }

    private function client(string $region, string $endpoint, string $accessKey, string $secret, int $timeout = 20): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $accessKey, 'secret' => $secret],
            'http' => ['connect_timeout' => 5, 'timeout' => $timeout],
        ]);
    }
}
