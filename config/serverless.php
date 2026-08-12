<?php

use App\Modules\Serverless\Support\ServerlessTestingDomains;

return [
    'provisioner' => env('SERVERLESS_PROVISIONER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | dply-managed serverless (platform account)
    |--------------------------------------------------------------------------
    |
    | When a function is created in "managed" mode dply runs it on its OWN
    | DigitalOcean Functions namespace (dply pays DO), rather than the
    | customer's connected credential. These are the platform OpenWhisk
    | credentials for that shared namespace; mirrors the Edge platform
    | delivery context. The managed create option is only offered when these
    | are configured (see ServerlessPlatformContext::configured()).
    */
    'managed' => [
        'api_host' => env('DPLY_SERVERLESS_DO_API_HOST'),
        'namespace' => env('DPLY_SERVERLESS_DO_NAMESPACE'),
        'access_key' => env('DPLY_SERVERLESS_DO_ACCESS_KEY'),
        'region' => env('DPLY_SERVERLESS_DO_REGION', 'nyc1'),
    ],

    'aws' => [
        'use_real_sdk' => filter_var(env('SERVERLESS_AWS_USE_REAL_SDK', false), FILTER_VALIDATE_BOOL),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'upload_zip_when_file_exists' => filter_var(env('SERVERLESS_AWS_UPLOAD_ZIP', false), FILTER_VALIDATE_BOOL),
        'zip_path_prefix' => ($z = env('SERVERLESS_AWS_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== ''
            ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR)
            : null,
        'zip_max_bytes' => (int) env('SERVERLESS_AWS_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        's3_allow_buckets' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERLESS_AWS_S3_ALLOW_BUCKETS', ''))))),
    ],

    'cloudflare' => [
        'use_real_api' => filter_var(env('SERVERLESS_CLOUDFLARE_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'compatibility_date' => env('CLOUDFLARE_WORKERS_COMPATIBILITY_DATE', '2024-11-01'),
        'script_path_prefix' => ($p = env('CLOUDFLARE_WORKER_SCRIPT_PATH_PREFIX')) !== null && trim((string) $p) !== ''
            ? rtrim(trim((string) $p), DIRECTORY_SEPARATOR)
            : null,
        'script_max_bytes' => (int) env('CLOUDFLARE_WORKER_SCRIPT_MAX_BYTES', 3 * 1024 * 1024),
    ],

    'netlify' => [
        'use_real_api' => filter_var(env('SERVERLESS_NETLIFY_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'api_token' => env('NETLIFY_AUTH_TOKEN'),
        'site_id' => env('NETLIFY_SITE_ID'),
        'zip_path_prefix' => ($z = env('NETLIFY_DEPLOY_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== ''
            ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR)
            : null,
        'zip_max_bytes' => (int) env('NETLIFY_DEPLOY_ZIP_MAX_BYTES', 45 * 1024 * 1024),
    ],

    'vercel' => [
        'use_real_api' => filter_var(env('SERVERLESS_VERCEL_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'token' => env('VERCEL_TOKEN'),
        'team_id' => env('VERCEL_TEAM_ID'),
        'project_id' => env('VERCEL_PROJECT_ID'),
        'project_name' => env('VERCEL_PROJECT_NAME'),
        'zip_path_prefix' => ($z = env('VERCEL_DEPLOY_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== ''
            ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR)
            : null,
        'zip_max_bytes' => (int) env('VERCEL_DEPLOY_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        'max_zip_entries' => (int) env('VERCEL_DEPLOY_MAX_ZIP_ENTRIES', 2000),
        'max_uncompressed_bytes' => (int) env('VERCEL_DEPLOY_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),
    ],

    'digitalocean' => [
        'use_real_api' => filter_var(env('SERVERLESS_DIGITALOCEAN_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'api_host' => env('DIGITALOCEAN_FUNCTIONS_API_HOST'),
        'namespace' => env('DIGITALOCEAN_FUNCTIONS_NAMESPACE'),
        'access_key' => env('DIGITALOCEAN_FUNCTIONS_ACCESS_KEY'),
        'zip_path_prefix' => ($z = env('DIGITALOCEAN_FUNCTIONS_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== ''
            ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR)
            : null,
        'zip_max_bytes' => (int) env('DIGITALOCEAN_FUNCTIONS_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        'default_action_kind' => env('DIGITALOCEAN_FUNCTIONS_ACTION_KIND', 'nodejs:18'),
        'default_action_main' => env('DIGITALOCEAN_FUNCTIONS_ACTION_MAIN', 'index.js'),
        'default_package' => trim((string) env('DIGITALOCEAN_FUNCTIONS_PACKAGE', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serverless function hostnames
    |--------------------------------------------------------------------------
    |
    | Every deployed function gets a friendly hostname on a dedicated apex —
    | {slug}.dply-serverless.cloud — rather than borrowing an entry from the
    | shared DPLY_TESTING_DOMAINS pool that BYO/VM previews use. Needs
    | *.dply-serverless.cloud DNS + wildcard TLS pointed at the dply app.
    |
    | Override with DPLY_SERVERLESS_TESTING_DOMAINS (comma-separated) for local
    | or staging apexes.
    */
    'testing_domains' => (static function (): array {
        $configured = trim((string) env('DPLY_SERVERLESS_TESTING_DOMAINS', ''));
        if ($configured === '') {
            return [ServerlessTestingDomains::DEFAULT_APEX];
        }

        return array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $configured),
        )));
    })(),

    /*
    | DNS target for a function's friendly hostname. An IP becomes an A record;
    | a hostname becomes a CNAME. When unset, the host CNAMEs onto the apex
    | (which must already resolve to the dply app). Falls back to the legacy
    | DPLY_SERVERLESS_FUNCTION_DNS_TARGET.
    */
    'testing_dns_target' => env(
        'DPLY_SERVERLESS_TESTING_DNS_TARGET',
        env('DPLY_SERVERLESS_FUNCTION_DNS_TARGET'),
    ),

    /*
    | Who hosts the serverless apex's DNS. dply-serverless.cloud is a Cloudflare
    | zone, so function hostnames are written through the Cloudflare API — not
    | the DigitalOcean DNS path the legacy DPLY_TESTING_DOMAINS pool uses.
    | Hostnames still on a legacy DO zone keep using DigitalOcean regardless of
    | this setting.
    |
    | The token needs Zone → DNS → Edit on the serverless zone. Reuses the Edge
    | platform token when a serverless-specific one isn't set.
    */
    'testing_dns' => [
        /*
         | How the apex answers for function hostnames:
         |
         |  wildcard  A single `*.{apex}` record (created once, by hand, in the
         |            Cloudflare dashboard and pointed at the dply app) already
         |            resolves every function. dply makes NO DNS API calls and
         |            needs NO DNS credential — it just records the hostname as
         |            live. Universal SSL covers one wildcard level, so TLS
         |            needs nothing either. This is the default.
         |
         |  auto      dply writes one record per function through the DNS API.
         |            Needs a token with Zone:DNS:Edit on the apex
         |            (DPLY_SERVERLESS_CF_API_TOKEN).
         |
         | Applies only to the serverless apex; legacy DPLY_TESTING_DOMAINS
         | hostnames always use the DigitalOcean API path.
         */
        'mode' => strtolower(trim((string) env('DPLY_SERVERLESS_TESTING_DNS_MODE', 'wildcard'))),
        'provider' => strtolower(trim((string) env('DPLY_SERVERLESS_TESTING_DNS_PROVIDER', 'cloudflare'))),
        'cloudflare_api_token' => env(
            'DPLY_SERVERLESS_CF_API_TOKEN',
            env('DPLY_EDGE_CF_API_TOKEN', env('CLOUDFLARE_API_TOKEN')),
        ),
    ],
];
