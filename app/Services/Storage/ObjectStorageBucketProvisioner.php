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
final class ObjectStorageBucketProvisioner
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

        $template = (string) ($meta['endpoint_template'] ?? '');
        $endpoint = $template !== '' ? str_replace('{region}', $region, $template) : '';
        if ($endpoint === '') {
            throw new InvalidArgumentException(__('Could not resolve the storage endpoint for this provider and region.'));
        }

        $providerLabel = (string) ($meta['label'] ?? $provider);

        $client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            // Path-style addressing for creation so we don't depend on the
            // per-bucket subdomain resolving the instant the bucket is made.
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $accessKey, 'secret' => $secret],
            'http' => ['connect_timeout' => 5, 'timeout' => 20],
        ]);

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
}
