<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\ProviderCredential;
use App\Support\Edge\EdgeOrgCredentialConfig;

class EdgeOrgInfraBootstrapper
{
    /**
     * @return array{bucket: string, kv_namespace_id: string, account_id: string}
     */
    public function bootstrap(
        ProviderCredential $credential,
        string $accountId,
        string $bucket,
        string $kvTitle,
        string $zoneName,
        string $workerScriptName,
    ): array {
        if ($credential->provider !== 'cloudflare') {
            throw new \InvalidArgumentException('Edge bootstrap requires a Cloudflare credential.');
        }

        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            throw new \RuntimeException('Cloudflare API token is missing.');
        }

        $client = new EdgeCloudflareClient($accountId, trim($token));
        $client->verifyToken();

        if (! $client->r2BucketExists($bucket)) {
            $client->createR2Bucket($bucket);
        }

        $kvId = $client->kvNamespaceIdByTitle($kvTitle);
        if ($kvId === null) {
            $created = $client->createKvNamespace($kvTitle);
            $kvId = is_string($created['id'] ?? null) ? $created['id'] : '';
        }

        if ($kvId === '') {
            throw new \RuntimeException('Could not resolve KV namespace id after bootstrap.');
        }

        $routePattern = $zoneName !== '' ? '*.'.$zoneName.'/*' : '';

        EdgeOrgCredentialConfig::merge($credential, [
            'account_id' => $accountId,
            'r2_bucket' => $bucket,
            'kv_namespace_id' => $kvId,
            'worker_script_name' => $workerScriptName,
            'worker_zone_name' => $zoneName,
            'worker_routes' => $routePattern !== '' ? [$routePattern] : [],
            'r2_key_prefix' => 'edge/',
            'bootstrapped_at' => now()->toIso8601String(),
        ]);

        return [
            'bucket' => $bucket,
            'kv_namespace_id' => $kvId,
            'account_id' => $accountId,
        ];
    }
}
