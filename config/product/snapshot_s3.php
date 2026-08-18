<?php

return [

    /*
    |--------------------------------------------------------------------------
    | S3 destination for the SnapshotService archive product
    |--------------------------------------------------------------------------
    | Single bucket per dply install in v1; per-org buckets pulled
    | from ProviderCredential land in v2 once the bucket-picker UI
    | exists. When `bucket` is empty the S3 destination factory
    | returns null so callers can fall back to LocalDiskDestination
    | gracefully.
    |
    | Works with any S3-compatible endpoint by setting `endpoint` —
    | DigitalOcean Spaces (https://nyc3.digitaloceanspaces.com),
    | Backblaze B2 (https://s3.<region>.backblazeb2.com), Cloudflare
    | R2 (https://<account>.r2.cloudflarestorage.com), or MinIO.
    | Leave empty to use real AWS S3.
    */

    'enabled' => filled(env('DPLY_SNAPSHOT_S3_BUCKET')),

    'bucket' => env('DPLY_SNAPSHOT_S3_BUCKET'),

    'region' => env('DPLY_SNAPSHOT_S3_REGION', 'us-east-1'),

    'endpoint' => env('DPLY_SNAPSHOT_S3_ENDPOINT'),

    'key' => env('DPLY_SNAPSHOT_S3_ACCESS_KEY'),

    'secret' => env('DPLY_SNAPSHOT_S3_SECRET'),

    'use_path_style_endpoint' => filter_var(env('DPLY_SNAPSHOT_S3_PATH_STYLE', false), FILTER_VALIDATE_BOOL),

    /*
    | Optional prefix prepended to every snapshot's S3 key. Useful for
    | sharing a single bucket across multiple dply environments
    | (production, staging) without their objects mingling.
    */
    'key_prefix' => env('DPLY_SNAPSHOT_S3_KEY_PREFIX', ''),

];
