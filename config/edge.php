<?php

use App\Support\Edge\EdgeTestingDomains;

return [

    /*
    |--------------------------------------------------------------------------
    | Fake edge (local / testing)
    |--------------------------------------------------------------------------
    | When enabled in allowed environments, skips real Cloudflare API calls
    | and stores artifacts on local disk. See FakeEdgeBackend.
    */
    'fake' => [
        'enabled' => filter_var(env('DPLY_FAKE_EDGE', false), FILTER_VALIDATE_BOOLEAN),
        'allowed_environments' => ['local', 'testing'],
        'storage_root' => storage_path('app/edge-fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare R2 (S3-compatible)
    |--------------------------------------------------------------------------
    */
    'r2' => [
        'bucket' => env('DPLY_EDGE_R2_BUCKET'),
        'region' => env('DPLY_EDGE_R2_REGION', 'auto'),
        'endpoint' => env('DPLY_EDGE_R2_ENDPOINT'),
        'key' => env('DPLY_EDGE_R2_ACCESS_KEY'),
        'secret' => env('DPLY_EDGE_R2_SECRET'),
        'use_path_style_endpoint' => filter_var(env('DPLY_EDGE_R2_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),
        'key_prefix' => env('DPLY_EDGE_R2_KEY_PREFIX', 'edge/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers + KV
    |--------------------------------------------------------------------------
    */
    'cloudflare' => [
        'account_id' => env('DPLY_EDGE_CF_ACCOUNT_ID'),
        'api_token' => env('DPLY_EDGE_CF_API_TOKEN'),
        'kv_namespace_id' => env('DPLY_EDGE_CF_KV_NAMESPACE_ID'),
        /**
         * Optional KV namespace ID for the EDGE_CACHE binding (hybrid
         * origin response cache, see B1 in docs/edge-roadmap.md). Set
         * to enable read-through caching for hybrid sites. When unset
         * the Worker deploys without the binding and the cache is a
         * silent no-op — safe to leave blank during rollout.
         */
        'cache_kv_namespace_id' => env('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID'),
        'worker_script_name' => env('DPLY_EDGE_CF_WORKER_SCRIPT', 'dply-edge'),
        'worker_zone_name' => env('DPLY_EDGE_CF_ZONE_NAME'),
        'worker_routes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DPLY_EDGE_CF_WORKER_ROUTES', '*.on-dply.site/*'))
        ))),
        'analytics_dataset' => env('DPLY_EDGE_CF_ANALYTICS_DATASET', 'dply_edge_requests'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker log ingest (access logs + performance rollups)
    |--------------------------------------------------------------------------
    */
    'log_ingest' => [
        'key' => env('DPLY_EDGE_LOG_INGEST_KEY'),
        'base_url' => env('DPLY_EDGE_LOG_INGEST_BASE_URL', env('APP_URL')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Logpush (http_requests → Dply ingest)
    |--------------------------------------------------------------------------
    */
    'logpush' => [
        'enabled' => filter_var(env('DPLY_EDGE_LOGPUSH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'secret' => env('DPLY_EDGE_LOGPUSH_SECRET'),
        'destination_url' => env('DPLY_EDGE_LOGPUSH_DESTINATION_URL', rtrim((string) env('APP_URL', ''), '/').'/hooks/edge/logpush'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge analytics retention (prune command)
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'access_logs_days' => (int) env('DPLY_EDGE_ACCESS_LOGS_DAYS', 7),
        'access_logs_keep_per_site' => (int) env('DPLY_EDGE_ACCESS_LOGS_KEEP', 500),
        'web_vitals_days' => (int) env('DPLY_EDGE_WEB_VITALS_DAYS', 30),
        'web_vitals_keep_per_site' => (int) env('DPLY_EDGE_WEB_VITALS_KEEP', 200),
        'performance_hourly_days' => (int) env('DPLY_EDGE_PERFORMANCE_HOURLY_DAYS', 45),
        'prefer_analytics_engine' => filter_var(env('DPLY_EDGE_PREFER_ANALYTICS_ENGINE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Build runner
    |--------------------------------------------------------------------------
    */
    'build' => [
        'docker_image' => env('DPLY_EDGE_BUILD_IMAGE', 'node:20-bookworm'),
        'timeout_seconds' => (int) env('DPLY_EDGE_BUILD_TIMEOUT', 900),
        'artifact_max_bytes' => (int) env('DPLY_EDGE_ARTIFACT_MAX_BYTES', 524_288_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Per-site override lives in sites.releases_to_keep (1..50). This value is
    | the fallback when a site hasn't set its own. Pruned deployments lose
    | their R2 artifacts but stay listed (with pruned_at set) for audit.
    */
    'retention' => [
        'default_keep' => (int) env('DPLY_EDGE_RETENTION_KEEP', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origin healthcheck (hybrid sites)
    |--------------------------------------------------------------------------
    | Runs before flipping KV to point at a new deployment. Failing checks
    | mark the deployment FAILED so an unhealthy origin never receives
    | Worker-proxied traffic. See OriginHealthcheckRunner.
    */
    'origin_healthcheck' => [
        'timeout_seconds' => (int) env('DPLY_EDGE_ORIGIN_HEALTHCHECK_TIMEOUT', 10),
        'retries' => (int) env('DPLY_EDGE_ORIGIN_HEALTHCHECK_RETRIES', 3),
        'retry_wait_ms' => (int) env('DPLY_EDGE_ORIGIN_HEALTHCHECK_RETRY_WAIT_MS', 1500),
    ],

    /*
    | Edge delivery hostnames — on-dply.* by default (e.g. on-dply.site).
    | Override with DPLY_EDGE_TESTING_DOMAINS; when unset, on-dply.* entries
    | from DPLY_TESTING_DOMAINS are preferred over generic BYO testing domains.
    */
    'testing_domains' => (static function (): array {
        $explicit = trim((string) env('DPLY_EDGE_TESTING_DOMAINS', ''));
        if ($explicit !== '') {
            return array_values(array_filter(array_map(
                static fn (string $value): string => strtolower(trim($value)),
                explode(',', $explicit),
            )));
        }

        return EdgeTestingDomains::defaultFromPool();
    })(),

    /*
    | DNS target for Edge delivery hostnames on DO-managed on-dply zones when
    | the zone is not the Cloudflare Worker zone. IP → A record; hostname → CNAME.
    | When unset, subdomains CNAME onto the zone apex.
    */
    'testing_dns_target' => env('DPLY_EDGE_TESTING_DNS_TARGET'),

    'default_backend' => 'dply_edge',

    /*
    | Edge delivery backends. Platform `dply_edge` is default; `org_cloudflare` uses
    | an org-linked Cloudflare credential bootstrapped via dply:edge:bootstrap-org.
    */
    'backends' => [
        'dply_edge' => [
            'label' => 'Dply Edge (managed)',
        ],
        'org_cloudflare' => [
            'label' => 'Your Cloudflare account',
        ],
    ],

    /*
    | Laravel filesystem disk name for Edge R2 uploads.
    | Registered in AppServiceProvider when bucket credentials are present.
    */
    'disk' => [
        'name' => 'edge_r2',
    ],

];
