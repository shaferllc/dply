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
        /*
         * Workers for Platforms dispatch namespace used to host
         * per-deployment SSR Worker scripts (Phase 4b). When unset,
         * SSR Edge sites can't be created — static + hybrid still
         * work. Bootstrap with `php artisan dply:edge:infra:bootstrap`
         * (auto-creates the namespace + prints the env line) or set
         * manually after creating one in the Cloudflare dashboard.
         */
        'dispatch_namespace_name' => env('DPLY_EDGE_CF_DISPATCH_NAMESPACE', 'dply-edge-ssr'),
        'dispatch_namespace_id' => env('DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID'),
        /*
         * Default compatibility flags + date for per-deployment SSR
         * scripts uploaded into the dispatch namespace. nodejs_compat
         * is required by Next.js/OpenNext; bump the date as Cloudflare
         * ships new runtime versions.
         */
        'ssr_script_compatibility_date' => env('DPLY_EDGE_CF_SSR_COMPAT_DATE', '2024-11-01'),
        'ssr_script_compatibility_flags' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DPLY_EDGE_CF_SSR_COMPAT_FLAGS', 'nodejs_compat'))
        ))),
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
        // Node 22 (current LTS) — Node 20 EOL is April 2026 and the latest
        // pnpm/Vite/Astro toolchains now require >=22.13. Override per-env
        // with DPLY_EDGE_BUILD_IMAGE if you need to pin older Node for a
        // specific deploy.
        'docker_image' => env('DPLY_EDGE_BUILD_IMAGE', 'node:22-bookworm'),
        'timeout_seconds' => (int) env('DPLY_EDGE_BUILD_TIMEOUT', 900),
        'artifact_max_bytes' => (int) env('DPLY_EDGE_ARTIFACT_MAX_BYTES', 524_288_000),
        // Persistent --mirror clone per repo so repeated builds skip
        // re-downloading the full history. Set git_cache_enabled=false
        // to bypass the mirror and clone directly (slower, but useful
        // when debugging a stale cache).
        'git_cache_enabled' => filter_var(env('DPLY_EDGE_BUILD_GIT_CACHE', true), FILTER_VALIDATE_BOOLEAN),
        'git_cache_dir' => env('DPLY_EDGE_BUILD_GIT_CACHE_DIR', storage_path('app/edge-git-cache')),
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

    /*
    |--------------------------------------------------------------------------
    | Usage guardrail
    |--------------------------------------------------------------------------
    | Soft cap on requests + egress per calendar month, per site. Evaluator
    | reads EdgeUsageSnapshot rows and computes a state (ok / warn / over).
    | Transitions fan out via the `edge.usage.over_budget` notification key
    | (already declared in config/notification_events.php). v1 does NOT
    | actually pause traffic at the Worker — it surfaces a banner and an
    | optional notification so flat-rate sites can't silently bleed margin.
    */
    'guardrail' => [
        'requests_per_month' => (int) env('DPLY_EDGE_GUARDRAIL_REQUESTS', 1_000_000),
        'bytes_per_month' => (int) env('DPLY_EDGE_GUARDRAIL_BYTES', 50 * 1024 * 1024 * 1024),
        'warn_at_percent' => max(1, min(99, (int) env('DPLY_EDGE_GUARDRAIL_WARN_PCT', 80))),
        // Reserved for a future cut — when true, sites in `over` state get
        // their deploy button disabled. Not consulted by the v1 evaluator.
        'auto_pause' => filter_var(env('DPLY_EDGE_GUARDRAIL_AUTO_PAUSE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Preview review hub — approve-to-promote workflow on Edge previews.
    */
    'preview_review' => [
        'min_approvals' => max(1, (int) env('DPLY_EDGE_PREVIEW_REVIEW_MIN_APPROVALS', 1)),
        'require_approval' => filter_var(env('DPLY_EDGE_PREVIEW_REVIEW_REQUIRE_APPROVAL', false), FILTER_VALIDATE_BOOLEAN),
        'block_open_comments' => filter_var(env('DPLY_EDGE_PREVIEW_REVIEW_BLOCK_OPEN_COMMENTS', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
