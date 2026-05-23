<?php

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
        'worker_script_name' => env('DPLY_EDGE_CF_WORKER_SCRIPT', 'dply-edge'),
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
    | Testing hostnames — defaults to DigitalOcean testing domain pool.
    */
    'testing_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'DPLY_EDGE_TESTING_DOMAINS',
            implode(',', (array) config('services.digitalocean.testing_domains', ['dply.host']))
        ))
    ))),

    'default_backend' => 'dply_edge',

    /*
    | Laravel filesystem disk name for Edge R2 uploads.
    | Registered in AppServiceProvider when bucket credentials are present.
    */
    'disk' => [
        'name' => 'edge_r2',
    ],

];
