<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         | Durable store for control-plane UI assets that must SURVIVE a redeploy:
         | site/server logos + org icons (dirs site-logos/, server-logos/,
         | org-logos/). These were previously on the `public` disk, which roots at
         | the per-release storage/app/public and is replaced (then pruned) on
         | every atomic deploy — so a captured favicon 404'd the next time dply
         | self-deployed.
         |
         | This disk roots at a release-INDEPENDENT path with ZERO config: on an
         | atomic-release host `current/.env` is always a symlink into the
         | persistent shared/ dir (the deploy guarantees it — shared/.env is
         | "sacred"; see deploy/ATOMIC_RELEASES.md), so we resolve that symlink and
         | drop assets in shared/site-assets, which outlives `current` flipping. In
         | dev (.env is a real file) it falls back to the local storage path.
         | SITE_ASSETS_PATH is an optional override for non-standard layouts.
         |
         | `serve => true` registers a framework route (storage.site_assets at
         | /site-assets) that streams the files through PHP — no nginx symlink and
         | no deploy coupling, the whole reason the previous approach was fragile.
         | Unique url path so it doesn't collide with the local/public disks at
         | /storage.
         */
        'site_assets' => [
            'driver' => 'local',
            'root' => env('SITE_ASSETS_PATH') ?: (static function (): string {
                // current/.env -> <ROOT>/shared/.env on atomic-release hosts;
                // realpath resolves the symlink so site-assets lands in shared/,
                // outside the releases/ tree. Resolved at config:cache time (after
                // the deploy symlinks .env in), so the absolute shared path bakes
                // into the cached config. Regular-file .env (dev) => local path.
                $env = base_path('.env');
                if (is_link($env) && ($real = realpath($env)) !== false) {
                    return dirname($real).'/site-assets';
                }

                return storage_path('app/site-assets');
            })(),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/site-assets',
            'serve' => true,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         | Private operator-only store for global feedback/bug-report screenshots
         | and attachments. Local disk in dev; point FEEDBACK_DISK_DRIVER at s3 with
         | an operator-owned bucket in production. Never public — admins read
         | through the authorized screenshot proxy route.
         */
        'feedback' => [
            'driver' => env('FEEDBACK_DISK_DRIVER', 'local'),
            'root' => storage_path('app/feedback'),
            'key' => env('FEEDBACK_S3_KEY'),
            'secret' => env('FEEDBACK_S3_SECRET'),
            'region' => env('FEEDBACK_S3_REGION'),
            'bucket' => env('FEEDBACK_S3_BUCKET'),
            'endpoint' => env('FEEDBACK_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('FEEDBACK_S3_USE_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
