<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Global download-staging bucket (operator-managed S3 store)
    |--------------------------------------------------------------------------
    |
    | A single operator-managed bucket used as an ephemeral DOWNLOAD TIER for
    | backups. On download, the durable artifact is copied here, a presigned GET
    | is handed to the browser, and the sweeper deletes the object after the TTL.
    | It is NOT durable storage. Key names mirror DatabaseBackupS3ClientFactory /
    | the secret-vault object store so the S3 wiring is identical.
    |
    | Provider-neutral: set BACKUP_STAGING_PROVIDER to any key under
    | config/object_storage.php → providers (e.g. digitalocean_spaces, hetzner,
    | aws_s3). The endpoint is derived from that provider's endpoint_template
    | ({region} substituted) unless BACKUP_STAGING_ENDPOINT is set explicitly.
    |
    */

    'connection' => [
        'enabled' => (bool) env('BACKUP_STAGING_ENABLED', false),
        'provider' => env('BACKUP_STAGING_PROVIDER', 'digitalocean_spaces'),
        'bucket' => env('BACKUP_STAGING_BUCKET'),
        'region' => env('BACKUP_STAGING_REGION', 'nyc3'),
        'endpoint' => env('BACKUP_STAGING_ENDPOINT'),
        'access_key' => env('BACKUP_STAGING_ACCESS_KEY'),
        'secret' => env('BACKUP_STAGING_SECRET'),
        'use_path_style' => (bool) env('BACKUP_STAGING_PATH_STYLE', false),
        'path' => env('BACKUP_STAGING_PATH', 'downloads'),
    ],

    /** How long a staged object (and its presigned GET) stays available before the sweeper deletes it. */
    'ttl_minutes' => (int) env('BACKUP_STAGING_TTL_MINUTES', 240),

    /** Presigned GET lifetime — kept equal to the TTL so a handed-out link is valid until the object is swept. */
    'presign_get_minutes' => (int) env('BACKUP_STAGING_PRESIGN_GET_MINUTES', 240),

    /** Presigned PUT lifetime — only needs to cover the server→bucket upload. */
    'presign_put_minutes' => (int) env('BACKUP_STAGING_PRESIGN_PUT_MINUTES', 30),

    /** Optional dedicated queue for the staging upload job (set to the worker queue in split deploys). */
    'upload_queue' => env('BACKUP_STAGING_UPLOAD_QUEUE', 'dply'),

];
