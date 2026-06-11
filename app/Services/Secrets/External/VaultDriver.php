<?php

declare(strict_types=1);

namespace App\Services\Secrets\External;

use App\Models\ExternalSecretStore;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HashiCorp Vault KV (v1 or v2). config: {endpoint, token, namespace?}.
 * Reference: "<path>#<field>", e.g. "secret/data/stripe#api_key".
 */
class VaultDriver extends AbstractSecretStoreDriver
{
    public function fetch(ExternalSecretStore $store, string $reference): string
    {
        $cfg = (array) $store->config;
        $endpoint = rtrim((string) ($cfg['endpoint'] ?? ''), '/');
        $token = (string) ($cfg['token'] ?? '');
        if ($endpoint === '' || $token === '') {
            throw new RuntimeException('Vault store is missing endpoint or token.');
        }

        [$path, $field] = self::splitReference($reference);
        if ($path === '') {
            throw new RuntimeException('Vault reference is missing a path.');
        }

        $request = Http::withHeaders(array_filter([
            'X-Vault-Token' => $token,
            'X-Vault-Namespace' => $cfg['namespace'] ?? null,
        ]))->acceptJson()->timeout(15);

        $response = $request->get($endpoint.'/v1/'.ltrim($path, '/'));
        if (! $response->successful()) {
            throw new RuntimeException("Vault returned HTTP {$response->status()} for '{$path}'.");
        }

        // KV v2 nests under data.data; KV v1 puts the map directly under data.
        $data = $response->json('data.data');
        if (! is_array($data)) {
            $data = $response->json('data');
        }
        if (! is_array($data)) {
            throw new RuntimeException("Vault response for '{$path}' has no secret data.");
        }

        return $this->pickField($data, $field, "vault:{$path}");
    }
}
