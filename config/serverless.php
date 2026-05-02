<?php

return [
    'provisioner' => env('SERVERLESS_PROVISIONER', 'local'),

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
];
