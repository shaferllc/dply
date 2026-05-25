<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;

/**
 * Translates the `bindings:` section of a deployment's snapshotted
 * dply.yaml (P10c) into Cloudflare binding descriptors the Workers
 * for Platforms script-upload API accepts.
 *
 * Used by both {@see EdgeSsrBundleUploader} and
 * {@see EdgeMiddlewareBundleUploader} so middleware and SSR scripts
 * see the same KV / R2 / D1 / Queues bindings the user declared.
 */
class EdgeRepoBindingTranslator
{
    /** Names dply already injects on every script — repo declarations conflicting with these are dropped. */
    private const RESERVED_NAMES = [
        'HOST_MAP',
        'ASSETS',
        'DEPLOYMENT_ID',
        'SITE_ID',
        'STORAGE_PREFIX',
        'EDGE_CACHE',
        'DISPATCHER',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function bindingsFor(EdgeDeployment $deployment): array
    {
        $config = is_array($deployment->repo_config) ? $deployment->repo_config : null;
        if (! is_array($config) || ! is_array($config['bindings'] ?? null)) {
            return [];
        }
        $declared = $config['bindings'];
        $out = [];

        foreach ((array) ($declared['kv'] ?? []) as $name => $namespaceId) {
            if (! $this->isUsableName($name) || ! is_string($namespaceId)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'kv_namespace', 'namespace_id' => $namespaceId];
        }
        foreach ((array) ($declared['r2'] ?? []) as $name => $bucketName) {
            if (! $this->isUsableName($name) || ! is_string($bucketName)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'r2_bucket', 'bucket_name' => $bucketName];
        }
        foreach ((array) ($declared['d1'] ?? []) as $name => $databaseId) {
            if (! $this->isUsableName($name) || ! is_string($databaseId)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'd1', 'id' => $databaseId];
        }
        foreach ((array) ($declared['queues'] ?? []) as $name => $queueName) {
            if (! $this->isUsableName($name) || ! is_string($queueName)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'queue', 'queue_name' => $queueName];
        }

        return $out;
    }

    private function isUsableName(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && ! in_array($name, self::RESERVED_NAMES, true);
    }
}
