<?php

declare(strict_types=1);

namespace App\Services\Deploy\ServerlessProviders\Aws;

use App\Contracts\AwsLambdaGateway;
use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\Support\ProvisionerConfigReport;
use RuntimeException;

final class AwsLambdaSdkProvisioner implements ServerlessFunctionProvisioner
{
    /**
     * @param  array<int, string>  $s3AllowBuckets
     */
    public function __construct(
        private readonly AwsLambdaGateway $gateway,
        private readonly string $defaultAwsRegion,
        private readonly bool $uploadZipWhenFileExists,
        private readonly ?string $zipPathPrefix,
        private readonly int $zipMaxBytes,
        private readonly array $s3AllowBuckets = [],
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        $gateway = $this->gatewayForProviderConfig($config);

        $meta = $this->tryDeployFromS3($gateway, $name, $artifactPath, $config);
        if ($meta === null && $this->shouldUploadZipFromPath($artifactPath)) {
            $meta = $gateway->updateFunctionCodeWithZip($name, $this->readZipBytes($artifactPath));
        }
        if ($meta === null) {
            $meta = $gateway->describeFunction($name);
        }

        return [
            'function_arn' => $meta['function_arn'],
            'revision_id' => $meta['revision_id'],
            'provider' => 'aws',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function gatewayForProviderConfig(array $config): AwsLambdaGateway
    {
        $resolved = AwsLambdaClientOptions::resolve($this->defaultAwsRegion, $config);
        if ($resolved['equals_default']) {
            return $this->gateway;
        }

        return AwsSdkLambdaGateway::fromClientConfig($resolved['client_config']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function s3AllowBucketsForConfig(array $config): array
    {
        if ($this->s3AllowBuckets === []) {
            return [];
        }

        $settings = [];
        if (isset($config['project']['settings']) && is_array($config['project']['settings'])) {
            $settings = $config['project']['settings'];
        }

        if (! array_key_exists('aws_s3_allow_buckets', $settings)) {
            return $this->s3AllowBuckets;
        }

        $projectBuckets = $this->normalizeS3AllowBucketList($settings['aws_s3_allow_buckets']);
        if ($projectBuckets === []) {
            return [];
        }

        $projectLower = array_map(strtolower(...), $projectBuckets);
        $effective = [];
        foreach ($this->s3AllowBuckets as $globalName) {
            if (in_array(strtolower($globalName), $projectLower, true)) {
                $effective[] = $globalName;
            }
        }

        return $effective;
    }

    /**
     * @return list<string>
     */
    private function normalizeS3AllowBucketList(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = array_map(trim(...), explode(',', $raw));
        } elseif (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                }
            }
        } else {
            return [];
        }

        return array_values(array_filter($parts, fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{function_arn: string, revision_id: string}|null
     */
    private function tryDeployFromS3(AwsLambdaGateway $gateway, string $functionName, string $artifactPath, array $config): ?array
    {
        $s3 = $this->parseS3ArtifactUri($artifactPath);
        if ($s3 === null) {
            return null;
        }

        $allowedBuckets = $this->s3AllowBucketsForConfig($config);
        if ($allowedBuckets === []) {
            if ($this->s3AllowBuckets === []) {
                throw new RuntimeException('artifact_path uses s3:// but no buckets are allowed. Set SERVERLESS_AWS_S3_ALLOW_BUCKETS.');
            }

            throw new RuntimeException('S3 artifact is not allowed for this project.');
        }

        $allowedLower = array_map(strtolower(...), $allowedBuckets);
        if (! in_array(strtolower($s3['bucket']), $allowedLower, true)) {
            throw new RuntimeException('S3 bucket "'.$s3['bucket'].'" is not allowed for this deploy.');
        }

        return $gateway->updateFunctionCodeFromS3(
            $functionName,
            $s3['bucket'],
            $s3['key'],
            $s3['version_id'],
        );
    }

    /**
     * @return array{bucket: string, key: string, version_id: string|null}|null
     */
    private function parseS3ArtifactUri(string $artifactPath): ?array
    {
        if (! str_starts_with(strtolower($artifactPath), 's3://')) {
            return null;
        }

        $parts = parse_url($artifactPath);
        if (($parts['scheme'] ?? '') !== 's3') {
            return null;
        }

        $bucket = isset($parts['host']) ? (string) $parts['host'] : '';
        $key = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        if ($bucket === '' || $key === '') {
            throw new RuntimeException('Invalid s3:// artifact_path: expected s3://bucket/object-key.');
        }

        $versionId = null;
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (isset($query['versionId']) && is_string($query['versionId']) && $query['versionId'] !== '') {
                $versionId = $query['versionId'];
            }
        }

        $this->assertSafeS3BucketAndKey($bucket, $key);

        return [
            'bucket' => $bucket,
            'key' => $key,
            'version_id' => $versionId,
        ];
    }

    private function assertSafeS3BucketAndKey(string $bucket, string $key): void
    {
        if (strlen($bucket) < 3 || strlen($bucket) > 63) {
            throw new RuntimeException('S3 bucket name length is invalid.');
        }
        if (! preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket) && ! preg_match('/^[a-z0-9]{3}$/', $bucket)) {
            throw new RuntimeException('S3 bucket name uses invalid characters or shape.');
        }
        if (str_contains($bucket, '..') || str_contains($bucket, '//')) {
            throw new RuntimeException('S3 bucket name is invalid.');
        }
        if (strlen($key) > 1024) {
            throw new RuntimeException('S3 object key exceeds maximum length (1024).');
        }
        if (str_contains($key, "\0") || str_contains($key, '\\')) {
            throw new RuntimeException('S3 object key contains invalid characters.');
        }
        if (preg_match('/%2e%2e/i', $key) === 1) {
            throw new RuntimeException('S3 object key must not contain encoded path traversal.');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new RuntimeException('S3 object key must not contain "." or ".." path segments.');
            }
        }
    }

    private function shouldUploadZipFromPath(string $artifactPath): bool
    {
        if (! $this->uploadZipWhenFileExists || $this->zipPathPrefix === null || $this->zipPathPrefix === '') {
            return false;
        }
        if (! is_file($artifactPath) || ! is_readable($artifactPath)) {
            return false;
        }

        return $this->pathIsUnderAllowedPrefix($artifactPath, $this->zipPathPrefix);
    }

    private function pathIsUnderAllowedPrefix(string $path, string $prefixBase): bool
    {
        $realPath = realpath($path);
        $realPrefix = realpath($prefixBase);
        if ($realPath === false || $realPrefix === false) {
            return false;
        }

        $prefixWithSep = rtrim($realPrefix, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $realPath === $realPrefix || str_starts_with($realPath, $prefixWithSep);
    }

    private function readZipBytes(string $path): string
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Artifact file is empty or unreadable.');
        }
        if ($size > $this->zipMaxBytes) {
            throw new RuntimeException('Artifact exceeds maximum zip size ('.$this->zipMaxBytes.' bytes).');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read artifact file.');
        }

        return $bytes;
    }
}
