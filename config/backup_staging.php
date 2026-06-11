<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Global download-staging bucket (Hetzner Object Storage)
    |--------------------------------------------------------------------------
    |
    | A single operator-managed bucket used as an ephemeral DOWNLOAD TIER for
    | backups. On download, the durable artifact is copied here, a presigned GET
    | is handed to the browser, and the sweeper deletes the object after the TTL.
    | It is NOT durable storage. Key names mirror DatabaseBackupS3ClientFactory /
    | the secret-vault object store so the S3 wiring is identical. The endpoint
    | defaults from config/object_storage.php → providers.hetzner.endpoint_template
    | ({region} substituted) unless an explicit endpoint is set.
    |
    */

    'hetzner' => [
        'enabled' => (bool) env('BACKUP_STAGING_HETZNER_ENABLED', false),
        'bucket' => env('BACKUP_STAGING_HETZNER_BUCKET'),
        'region' => env('BACKUP_STAGING_HETZNER_REGION', 'fsn1'),
        'endpoint' => env('BACKUP_STAGING_HETZNER_ENDPOINT'),
        'access_key' => env('BACKUP_STAGING_HETZNER_ACCESS_KEY'),
        'secret' => env('BACKUP_STAGING_HETZNER_SECRET'),
        'use_path_style' => (bool) env('BACKUP_STAGING_HETZNER_PATH_STYLE', false),
        'path' => env('BACKUP_STAGING_HETZNER_PATH', 'downloads'),
    ],

    /** How long a staged object (and its presigned GET) stays available before the sweeper deletes it. */
    'ttl_minutes' => (int) env('BACKUP_STAGING_TTL_MINUTES', 240),

    /** Presigned GET lifetime — kept equal to the TTL so a handed-out link is valid until the object is swept. */
    'presign_get_minutes' => (int) env('BACKUP_STAGING_PRESIGN_GET_MINUTES', 240),

    /** Presigned PUT lifetime — only needs to cover the server→Hetzner upload. */
    'presign_put_minutes' => (int) env('BACKUP_STAGING_PRESIGN_PUT_MINUTES', 30),

    /** Optional dedicated queue for the staging upload job (set to the worker queue in split deploys). */
    'upload_queue' => env('BACKUP_STAGING_UPLOAD_QUEUE'),

];
