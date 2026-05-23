<?php

declare(strict_types=1);

namespace App\Support\Edge;

use App\Models\ProviderCredential;
use RuntimeException;

/**
 * Cloudflare credentials + infra targets for a single Edge delivery scope
 * (platform config or an org-owned provider credential).
 */
final readonly class EdgeDeliveryContext
{
    /**
     * @param  list<string>  $workerRoutes
     */
    public function __construct(
        public string $backendKey,
        public string $accountId,
        public string $apiToken,
        public string $kvNamespaceId,
        public string $r2Bucket,
        public string $r2AccessKey,
        public string $r2Secret,
        public string $r2Endpoint,
        public string $r2KeyPrefix,
        public string $workerScriptName,
        public string $workerZoneName,
        public array $workerRoutes,
        public string $diskName,
        public ?string $providerCredentialId = null,
    ) {}

    public static function platform(): self
    {
        $accountId = trim((string) config('edge.cloudflare.account_id'));
        $endpoint = EdgePlatformCredentials::r2Endpoint();

        return new self(
            backendKey: 'dply_edge',
            accountId: $accountId,
            apiToken: trim((string) config('edge.cloudflare.api_token')),
            kvNamespaceId: trim((string) config('edge.cloudflare.kv_namespace_id')),
            r2Bucket: trim((string) config('edge.r2.bucket')),
            r2AccessKey: trim((string) config('edge.r2.key')),
            r2Secret: trim((string) config('edge.r2.secret')),
            r2Endpoint: $endpoint,
            r2KeyPrefix: trim((string) config('edge.r2.key_prefix', 'edge/')),
            workerScriptName: trim((string) config('edge.cloudflare.worker_script_name', 'dply-edge')),
            workerZoneName: trim((string) config('edge.cloudflare.worker_zone_name')),
            workerRoutes: EdgePlatformCredentials::workerRoutes(),
            diskName: (string) config('edge.disk.name', 'edge_r2'),
        );
    }

    public static function fromProviderCredential(ProviderCredential $credential): self
    {
        if ($credential->provider !== 'cloudflare') {
            throw new RuntimeException('Edge BYO delivery requires a Cloudflare provider credential.');
        }

        $edge = EdgeOrgCredentialConfig::read($credential);
        $accountId = trim((string) ($edge['account_id'] ?? ''));
        $kvId = trim((string) ($edge['kv_namespace_id'] ?? ''));
        $bucket = trim((string) ($edge['r2_bucket'] ?? ''));
        $accessKey = trim((string) ($edge['r2_access_key'] ?? ''));
        $secret = trim((string) ($edge['r2_secret'] ?? ''));
        $endpoint = trim((string) ($edge['r2_endpoint'] ?? ''));
        if ($endpoint === '' && $accountId !== '') {
            $endpoint = 'https://'.$accountId.'.r2.cloudflarestorage.com';
        }

        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Cloudflare API token is missing on the selected credential.');
        }

        $missing = [];
        if ($accountId === '') {
            $missing[] = 'account_id';
        }
        if ($kvId === '') {
            $missing[] = 'kv_namespace_id';
        }
        if ($bucket === '') {
            $missing[] = 'r2_bucket';
        }
        if ($accessKey === '') {
            $missing[] = 'r2_access_key';
        }
        if ($secret === '') {
            $missing[] = 'r2_secret';
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Cloudflare credential is not bootstrapped for Edge. Run: php artisan dply:edge:bootstrap-org '.$credential->id
                .' (missing: '.implode(', ', $missing).')',
            );
        }

        $routes = $edge['worker_routes'] ?? [];
        if (! is_array($routes)) {
            $routes = [];
        }

        return new self(
            backendKey: 'org_cloudflare',
            accountId: $accountId,
            apiToken: trim($token),
            kvNamespaceId: $kvId,
            r2Bucket: $bucket,
            r2AccessKey: $accessKey,
            r2Secret: $secret,
            r2Endpoint: rtrim($endpoint, '/'),
            r2KeyPrefix: trim((string) ($edge['r2_key_prefix'] ?? 'edge/')),
            workerScriptName: trim((string) ($edge['worker_script_name'] ?? 'dply-edge')),
            workerZoneName: trim((string) ($edge['worker_zone_name'] ?? '')),
            workerRoutes: array_values(array_filter(array_map(
                static fn ($route) => is_string($route) ? trim($route) : '',
                $routes,
            ))),
            diskName: 'edge_r2_org_'.$credential->id,
            providerCredentialId: (string) $credential->id,
        );
    }

    public function isPlatform(): bool
    {
        return $this->backendKey === 'dply_edge';
    }

    public function isBootstrapped(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && $this->kvNamespaceId !== ''
            && $this->r2Bucket !== ''
            && $this->r2AccessKey !== ''
            && $this->r2Secret !== ''
            && $this->r2Endpoint !== '';
    }
}
