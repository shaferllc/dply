<?php

declare(strict_types=1);

namespace App\Support\Edge;

/**
 * Validates platform-level Edge credentials from config/edge.php.
 */
class EdgePlatformCredentials
{
    /**
     * @return list<string> Human-readable missing requirement labels.
     */
    public static function missing(): array
    {
        if (FakeEdgeProvision::enabled()) {
            return [];
        }

        $missing = [];

        foreach (self::requiredStringKeys() as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }

        if (self::r2Endpoint() === '') {
            $missing[] = 'DPLY_EDGE_R2_ENDPOINT (or DPLY_EDGE_CF_ACCOUNT_ID to derive it)';
        }

        return $missing;
    }

    public static function isProductionReady(): bool
    {
        return self::missing() === [];
    }

    public static function r2Endpoint(): string
    {
        $endpoint = trim((string) config('edge.r2.endpoint'));
        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        $accountId = trim((string) config('edge.cloudflare.account_id'));
        if ($accountId === '') {
            return '';
        }

        return 'https://'.$accountId.'.r2.cloudflarestorage.com';
    }

    /**
     * @return array<string, string>
     */
    public static function summary(): array
    {
        return [
            'fake_edge' => FakeEdgeProvision::enabled() ? 'true' : 'false',
            'r2_bucket' => (string) config('edge.r2.bucket'),
            'r2_endpoint' => self::r2Endpoint(),
            'r2_key_prefix' => (string) config('edge.r2.key_prefix'),
            'cf_account_id' => (string) config('edge.cloudflare.account_id'),
            'cf_kv_namespace_id' => (string) config('edge.cloudflare.kv_namespace_id'),
            'cf_worker_script' => (string) config('edge.cloudflare.worker_script_name'),
            'cf_worker_routes' => implode(', ', self::workerRoutes()),
            'cf_zone_name' => (string) config('edge.cloudflare.worker_zone_name'),
            'testing_domains' => implode(', ', (array) config('edge.testing_domains', [])),
        ];
    }

    /**
     * @return list<string>
     */
    public static function workerRoutes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('edge.cloudflare.worker_routes', []),
        )));
    }

    /**
     * @return array<string, string>
     */
    private static function requiredStringKeys(): array
    {
        return [
            'DPLY_EDGE_R2_BUCKET' => trim((string) config('edge.r2.bucket')),
            'DPLY_EDGE_R2_ACCESS_KEY' => trim((string) config('edge.r2.key')),
            'DPLY_EDGE_R2_SECRET' => trim((string) config('edge.r2.secret')),
            'DPLY_EDGE_CF_ACCOUNT_ID' => trim((string) config('edge.cloudflare.account_id')),
            'DPLY_EDGE_CF_API_TOKEN' => trim((string) config('edge.cloudflare.api_token')),
            'DPLY_EDGE_CF_KV_NAMESPACE_ID' => trim((string) config('edge.cloudflare.kv_namespace_id')),
        ];
    }
}
