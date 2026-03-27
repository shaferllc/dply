<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Function provisioner driver
    |--------------------------------------------------------------------------
    |
    | Which ServerlessFunctionProvisioner implementation the container binds.
    | Stubs: local, aws, digitalocean, azure, gcp, cloudflare, netlify, vercel.
    | Real adapters: aws (SDK), cloudflare (REST), digitalocean (OpenWhisk REST zip), netlify (REST zip), vercel (REST zip → files). Others are stubs until wired.
    |
    */

    'provisioner' => env('SERVERLESS_PROVISIONER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | AWS Lambda (SDK)
    |--------------------------------------------------------------------------
    |
    | When provisioner is "aws" and use_real_sdk is true, uses Lambda GetFunction and
    | optionally UpdateFunctionCode with a local zip when SERVERLESS_AWS_UPLOAD_ZIP is true
    | and SERVERLESS_AWS_ZIP_PATH_PREFIX is set (artifact path must resolve under that dir).
    |
    | artifact_path may be s3://bucket/object-key.zip (optional ?versionId= for S3ObjectVersion)
    | when SERVERLESS_AWS_S3_ALLOW_BUCKETS lists that bucket (comma-separated allow list). Linked projects
    | may further narrow buckets via settings key aws_s3_allow_buckets (intersection with this list).
    |
    */

    'aws' => [
        'use_real_sdk' => filter_var(env('SERVERLESS_AWS_USE_REAL_SDK', false), FILTER_VALIDATE_BOOL),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'upload_zip_when_file_exists' => filter_var(env('SERVERLESS_AWS_UPLOAD_ZIP', false), FILTER_VALIDATE_BOOL),
        'zip_path_prefix' => ($z = env('SERVERLESS_AWS_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== '' ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR) : null,
        'zip_max_bytes' => (int) env('SERVERLESS_AWS_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        's3_allow_buckets' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERLESS_AWS_S3_ALLOW_BUCKETS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers (REST API)
    |--------------------------------------------------------------------------
    |
    | When provisioner is "cloudflare" and use_real_api is true, uploads the worker script from a
    | local file path that must resolve under script_path_prefix (same safety model as AWS zip prefix).
    | Requires Account ID + API token with Workers Scripts:Edit.
    |
    */

    'cloudflare' => [
        'use_real_api' => filter_var(env('SERVERLESS_CLOUDFLARE_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'compatibility_date' => env('CLOUDFLARE_WORKERS_COMPATIBILITY_DATE', '2024-11-01'),
        'script_path_prefix' => ($p = env('CLOUDFLARE_WORKER_SCRIPT_PATH_PREFIX')) !== null && trim((string) $p) !== '' ? rtrim(trim((string) $p), DIRECTORY_SEPARATOR) : null,
        'script_max_bytes' => (int) env('CLOUDFLARE_WORKER_SCRIPT_MAX_BYTES', 3 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Netlify (REST — zip site deploy)
    |--------------------------------------------------------------------------
    |
    | When provisioner is "netlify" and use_real_api is true, POSTs a local .zip under
    | zip_path_prefix to POST /api/v1/sites/{site_id}/deploys. Token + site_id may come from
    | NETLIFY_* env or from linked project credentials / settings.
    |
    */

    'netlify' => [
        'use_real_api' => filter_var(env('SERVERLESS_NETLIFY_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'api_token' => env('NETLIFY_AUTH_TOKEN'),
        'site_id' => env('NETLIFY_SITE_ID'),
        'zip_path_prefix' => ($z = env('NETLIFY_DEPLOY_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== '' ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR) : null,
        'zip_max_bytes' => (int) env('NETLIFY_DEPLOY_ZIP_MAX_BYTES', 45 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vercel (REST — zip expanded to deployment files)
    |--------------------------------------------------------------------------
    |
    | When provisioner is "vercel" and use_real_api is true, reads a local .zip under
    | zip_path_prefix, expands entries, and POSTs JSON to /v13/deployments. Use either
    | project_id (prj_…) or project_name for the target project. Optional team_id query.
    |
    */

    'vercel' => [
        'use_real_api' => filter_var(env('SERVERLESS_VERCEL_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'token' => env('VERCEL_TOKEN'),
        'team_id' => env('VERCEL_TEAM_ID'),
        'project_id' => env('VERCEL_PROJECT_ID'),
        'project_name' => env('VERCEL_PROJECT_NAME'),
        'zip_path_prefix' => ($z = env('VERCEL_DEPLOY_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== '' ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR) : null,
        'zip_max_bytes' => (int) env('VERCEL_DEPLOY_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        'max_zip_entries' => (int) env('VERCEL_DEPLOY_MAX_ZIP_ENTRIES', 2000),
        'max_uncompressed_bytes' => (int) env('VERCEL_DEPLOY_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | DigitalOcean Functions (OpenWhisk-compatible REST)
    |--------------------------------------------------------------------------
    |
    | When provisioner is "digitalocean" and use_real_api is true, PUTs a local .zip
    | under zip_path_prefix to the namespace action API (JSON exec with base64 zip).
    | Requires api host, namespace id, and namespace access key (dof_v1_…:secret) from
    | the control panel or doctl. Optional default package name for non-default packages.
    |
    */

    'digitalocean' => [
        'use_real_api' => filter_var(env('SERVERLESS_DIGITALOCEAN_USE_REAL_API', false), FILTER_VALIDATE_BOOL),
        'api_host' => env('DIGITALOCEAN_FUNCTIONS_API_HOST'),
        'namespace' => env('DIGITALOCEAN_FUNCTIONS_NAMESPACE'),
        'access_key' => env('DIGITALOCEAN_FUNCTIONS_ACCESS_KEY'),
        'zip_path_prefix' => ($z = env('DIGITALOCEAN_FUNCTIONS_ZIP_PATH_PREFIX')) !== null && trim((string) $z) !== '' ? rtrim(trim((string) $z), DIRECTORY_SEPARATOR) : null,
        'zip_max_bytes' => (int) env('DIGITALOCEAN_FUNCTIONS_ZIP_MAX_BYTES', 45 * 1024 * 1024),
        'default_action_kind' => env('DIGITALOCEAN_FUNCTIONS_ACTION_KIND', 'nodejs:18'),
        'default_action_main' => env('DIGITALOCEAN_FUNCTIONS_ACTION_MAIN', 'index.js'),
        'default_package' => trim((string) env('DIGITALOCEAN_FUNCTIONS_PACKAGE', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook (HMAC, same header semantics as BYO + dply-core WebhookSignature)
    |--------------------------------------------------------------------------
    */

    'webhook_secret' => env('SERVERLESS_WEBHOOK_SECRET'),

    /** Comma-separated IPv4 addresses or CIDRs; empty = allow any IP. */
    'webhook_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('SERVERLESS_WEBHOOK_ALLOWED_IPS', ''))))),

    'webhook_timestamp_tolerance' => (int) env('SERVERLESS_WEBHOOK_TIMESTAMP_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | API deploy (Bearer token)
    |--------------------------------------------------------------------------
    */

    'api_token' => env('SERVERLESS_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Defaults when JSON body omits fields
    |--------------------------------------------------------------------------
    */

    'default_function_name' => env('SERVERLESS_DEFAULT_FUNCTION_NAME', 'app'),

    'default_runtime' => env('SERVERLESS_DEFAULT_RUNTIME', 'provided.al2023'),

    'default_artifact_path' => env('SERVERLESS_DEFAULT_ARTIFACT_PATH', '/dev/null'),

];
