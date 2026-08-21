<?php

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
    | {slug}-{idHash8}.dply-serverless.cloud — rather than borrowing an entry
    | from the shared DPLY_TESTING_DOMAINS pool that BYO/VM previews use. Needs
    | *.dply-serverless.cloud DNS + wildcard TLS pointed at the dply app.
    |
    | Override with DPLY_SERVERLESS_TESTING_DOMAINS (comma-separated) for local
    | or staging apexes.
    */
    'testing_domains' => (require dirname(__DIR__).'/product/testing_domains.php')['serverless'] ?? ['dply-serverless.cloud'],

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

    /*
    |--------------------------------------------------------------------------
    | Published front-end assets
    |--------------------------------------------------------------------------
    |
    | A Functions Laravel app publishes `public/build` off the function (the
    | 1 MB HTTP response cap makes serving Vite CSS through it impossible) into
    | one shared bucket, one prefix per site, delivered by Cloudflare.
    |
    | See ServerlessAssetHost for why the prefix and the hostname's DNS label
    | are the same string, and for the Cloudflare rule that relies on it.
    |
    | DNS needs nothing extra in the default `wildcard` testing_dns mode: the
    | hand-created `*.{apex}` record already answers for {label}-assets.{apex},
    | and Universal SSL covers one wildcard level.
    */
    'assets' => [
        /*
         | Refuse to publish a build larger than this. Sized for "someone
         | committed a video into public/", not as a billing tier — the priced
         | allowance sits far below it, so ordinary overage is billed and only
         | the absurd is refused. Checked against the local directory before
         | anything uploads, so the deploy fails while the user can still fix it.
         */
        'max_bytes' => (int) env('DPLY_SERVERLESS_ASSET_MAX_BYTES', 5 * 1024 ** 3),

        /*
         | How many successful deploys' assets to keep. Publishing is additive
         | and filenames are content-hashed, so the union of the last N builds
         | is exactly what rollback to any of them needs. Below this the
         | garbage collector deletes nothing at all.
         */
        'retain_deploys' => max(1, (int) env('DPLY_SERVERLESS_ASSET_RETAIN_DEPLOYS', 5)),

        /*
         | Clock-skew guard on the GC cutoff. Object mtimes come from the
         | storage provider while deploy timestamps come from the app, so the
         | cutoff is nudged back before anything is deleted.
         */
        'gc_grace_hours' => max(0, (int) env('DPLY_SERVERLESS_ASSET_GC_GRACE_HOURS', 2)),

        /*
         | Percentage of the billed allowance at which the guardrail flips to
         | `warn`. Delivery is never cut off past 100% — see
         | ServerlessAssetGuardrailStatus for why.
         */
        'warn_at_percent' => max(1, min(99, (int) env('DPLY_SERVERLESS_ASSET_WARN_AT_PERCENT', 80))),

        'cdn' => [
            /*
             | Turns ASSET_URL into the site's own CDN hostname. Off leaves the
             | existing behaviour intact (disk URL, else same-origin /build via
             | the function proxy), which is what makes the cutover reversible:
             | flip this back and sites fall to a path that still works because
             | the controller reads through the same disk.
             */
            'enabled' => filter_var(env('DPLY_SERVERLESS_ASSET_CDN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        ],

        /*
         | Per-site buckets the APP writes to (uploads, user media) — distinct
         | from the shared, platform-written asset bucket above.
         |
         | These have to be separate. Spaces grants are scoped per bucket, not
         | per prefix, so a key handed to a customer's app to write its own
         | files could read and overwrite every other tenant's. A bucket each,
         | with a key granted to just that bucket, is the only safe shape — and
         | it costs nothing, since one Spaces subscription covers many buckets
         | and shares its allowances across them.
         |
         | Provisioned on demand, then injected into the function's managed
         | .env like any attached resource. See ServerlessAppBucketProvisioner.
         */
        'app_buckets' => [
            'enabled' => filter_var(env('DPLY_SERVERLESS_APP_BUCKETS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'region' => env('DPLY_SERVERLESS_APP_BUCKETS_REGION', 'nyc3'),
            'name_prefix' => env('DPLY_SERVERLESS_APP_BUCKETS_NAME_PREFIX', 'dply-fn'),
            /*
             | Laravel disk name, and the env prefix it implies
             | (AWS_UPLOADS_BUCKET, AWS_UPLOADS_ACCESS_KEY_ID, …).
             | Deliberately NOT `s3`: the primary disk stays the operator's to
             | attach, so dply never silently repoints FILESYSTEM_DISK at a
             | bucket the app did not ask for.
             */
            'disk' => env('DPLY_SERVERLESS_APP_BUCKETS_DISK', 'uploads'),
            /*
             | Prefix browser-direct uploads are staged under, and how long an
             | unclaimed one survives there. The app promotes an upload out of
             | this prefix once it accepts it, so anything left is an upload
             | that was never claimed — one day is the finest granularity an
             | S3 lifecycle rule offers. Both are baked into each bucket's
             | lifecycle policy at provision time, so changing them only
             | affects buckets provisioned afterwards.
             */
            'tmp_prefix' => env('DPLY_SERVERLESS_APP_BUCKETS_TMP_PREFIX', 'tmp/'),
            'tmp_expiry_days' => (int) env('DPLY_SERVERLESS_APP_BUCKETS_TMP_EXPIRY_DAYS', 1),
        ],
    ],
];
