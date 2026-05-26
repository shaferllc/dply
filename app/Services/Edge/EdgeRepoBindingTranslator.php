<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Builds the list of Cloudflare binding descriptors uploaded with
 * each Edge worker script.
 *
 * Two sources:
 *   1. The per-site default `env.KV` namespace (always injected, lazily
 *      provisioned in the user's CF account by EnsureDefaultEdgeBindings)
 *   2. Bindings declared in the repo's `wrangler.toml` (discovered at
 *      build time by WranglerBindingsExtractor; values can be titles
 *      that EdgeBindingsAutoResolver creates on first use)
 *
 * Declared bindings override the default if they collide on name.
 * Used by both {@see EdgeSsrBundleUploader} and
 * {@see EdgeMiddlewareBundleUploader}.
 */
class EdgeRepoBindingTranslator
{
    /** Names the platform Worker already injects — repo + defaults are dropped if they collide. */
    private const RESERVED_NAMES = [
        'HOST_MAP',
        'ASSETS',
        'DEPLOYMENT_ID',
        'SITE_ID',
        'STORAGE_PREFIX',
        'EDGE_CACHE',
        'DISPATCHER',
    ];

    public function __construct(
        private readonly EdgeBindingsAutoResolver $autoResolver,
        private readonly EnsureDefaultEdgeBindings $defaultBindings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function bindingsFor(EdgeDeployment $deployment): array
    {
        $config = is_array($deployment->repo_config) ? $deployment->repo_config : [];
        $site = $deployment->site;

        // Resolve wrangler-declared bindings (titles → CF IDs, with
        // auto-create on first use). The dply.yaml `bindings:` schema
        // is no longer parsed; everything declarative comes through
        // wrangler.toml via WranglerBindingsExtractor at build time.
        $declared = [];
        if (is_array($config['bindings'] ?? null) && $site instanceof Site) {
            $declared = $this->autoResolver->resolve($site, $deployment) ?: $config['bindings'];
        } elseif (is_array($config['bindings'] ?? null)) {
            $declared = $config['bindings'];
        }

        $declaredNames = $this->collectDeclaredNames($declared);

        $out = [];

        // env.KV — per-site default. Skipped when declared overrides it
        // or when the platform reserves the name (defensive).
        $defaultKvId = $site instanceof Site ? $this->defaultBindings->ensure($site)['kv'] : null;
        if (is_string($defaultKvId) && ! in_array('KV', $declaredNames, true) && ! in_array('KV', self::RESERVED_NAMES, true)) {
            $out[] = ['name' => 'KV', 'type' => 'kv_namespace', 'namespace_id' => $defaultKvId];
        }

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

    /**
     * @param  array<string, array<string, string>>  $declared
     * @return list<string>
     */
    private function collectDeclaredNames(array $declared): array
    {
        $names = [];
        foreach (['kv', 'r2', 'd1', 'queues'] as $kind) {
            $bucket = $declared[$kind] ?? null;
            if (! is_array($bucket)) {
                continue;
            }
            foreach (array_keys($bucket) as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private function isUsableName(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && ! in_array($name, self::RESERVED_NAMES, true);
    }
}
