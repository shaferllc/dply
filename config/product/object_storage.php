<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Object storage providers
    |--------------------------------------------------------------------------
    |
    | S3-compatible providers offered when connecting object storage to a site
    | (the "storage" SiteBinding). Each provider supplies an endpoint template
    | whose {region} placeholder is substituted at attach time, plus the regions
    | it offers. AWS uses the SDK's default regional endpoint, so its template is
    | empty and AWS_ENDPOINT is left unset. The custom provider has no regions
    | and expects the operator to supply the endpoint directly.
    |
    | Provider slugs match the conventions already used elsewhere (CloudBucket /
    | BackupConfiguration: aws_s3, digitalocean_spaces; ServerProvider: hetzner).
    |
    */

    'providers' => [

        'aws_s3' => [
            'label' => 'AWS S3',
            'endpoint_template' => '', // SDK derives the endpoint from the region.
            'provision' => false, // Attach-only for now (CreateBucket needs LocationConstraint handling).
            'pricing_url' => 'https://aws.amazon.com/s3/pricing/',
            'pricing_note' => 'Billed by AWS on your own account. Per-GB/mo (us-east-1, approx): Standard $0.023, Standard-IA $0.0125, Glacier Instant Retrieval $0.004, Glacier Flexible $0.0036, Deep Archive $0.00099. Retrieval/request fees apply to colder classes.',
            // Per-object S3 storage classes. `restore` = the object must be
            // thawed (RestoreObject) before it can be downloaded.
            'storage_classes' => [
                'STANDARD' => ['label' => 'Standard', 'restore' => false, 'note' => 'Default. Instant download.'],
                'STANDARD_IA' => ['label' => 'Standard-IA (infrequent access)', 'restore' => false, 'note' => 'Cheaper storage + small retrieval fee. Instant download.'],
                'INTELLIGENT_TIERING' => ['label' => 'Intelligent-Tiering', 'restore' => false, 'note' => 'Auto-tiers by access pattern. Instant download.'],
                'GLACIER_IR' => ['label' => 'Glacier Instant Retrieval', 'restore' => false, 'note' => 'Archive price, instant download. Great for backups.'],
                'GLACIER' => ['label' => 'Glacier Flexible Retrieval', 'restore' => true, 'note' => 'Cheapest tier with restore. Download needs a thaw (minutes–hours).'],
                'DEEP_ARCHIVE' => ['label' => 'Glacier Deep Archive', 'restore' => true, 'note' => 'Lowest cost. Download needs a thaw (up to ~12h).'],
            ],
            'regions' => [
                'us-east-1' => 'US East (N. Virginia) · us-east-1',
                'us-east-2' => 'US East (Ohio) · us-east-2',
                'us-west-1' => 'US West (N. California) · us-west-1',
                'us-west-2' => 'US West (Oregon) · us-west-2',
                'eu-west-1' => 'EU (Ireland) · eu-west-1',
                'eu-west-2' => 'EU (London) · eu-west-2',
                'eu-central-1' => 'EU (Frankfurt) · eu-central-1',
                'ap-southeast-1' => 'Asia Pacific (Singapore) · ap-southeast-1',
                'ap-southeast-2' => 'Asia Pacific (Sydney) · ap-southeast-2',
                'ap-northeast-1' => 'Asia Pacific (Tokyo) · ap-northeast-1',
            ],
        ],

        'digitalocean_spaces' => [
            'label' => 'DigitalOcean Spaces',
            'endpoint_template' => 'https://{region}.digitaloceanspaces.com',
            'provision' => true,
            // dply can mint the S3 (Spaces) keys via the DO API token, so it
            // creates the keys AND the bucket with no manual key entry. The
            // cloud-provider slug whose ProviderCredential token is used.
            'api_managed' => true,
            'api_provider' => 'digitalocean',
            'pricing_url' => 'https://docs.digitalocean.com/products/spaces/details/pricing/',
            'pricing_note' => 'Billed by DigitalOcean on your own account. Spaces base $5/mo includes 250 GiB storage + 1 TiB transfer, then $0.02/GiB/mo. Cold Storage: $0.007/GiB/mo + $0.01/GiB retrieved (objects <128 KiB billed as 128 KiB).',
            // DO cold storage is a BUCKET-level tier that can only be created in
            // the DigitalOcean control panel (not the S3 API). dply provisions
            // standard buckets; connect a cold bucket via "Connect existing".
            'cold_note' => 'Cold Storage buckets are ~3× cheaper but can only be created in the DigitalOcean console. Create one there, then add it here with “Connect existing”.',
            'cold_console_url' => 'https://cloud.digitalocean.com/spaces/new',
            'regions' => [
                'nyc3' => 'New York · nyc3',
                'sfo2' => 'San Francisco · sfo2',
                'sfo3' => 'San Francisco · sfo3',
                'ams3' => 'Amsterdam · ams3',
                'sgp1' => 'Singapore · sgp1',
                'fra1' => 'Frankfurt · fra1',
                'syd1' => 'Sydney · syd1',
                'blr1' => 'Bangalore · blr1',
            ],
        ],

        'hetzner' => [
            'label' => 'Hetzner Object Storage',
            'endpoint_template' => 'https://{region}.your-objectstorage.com',
            'provision' => true,
            // Hetzner has no API to mint object-storage keys, so the operator
            // generates them in the console once. Surface where to get them.
            'key_help' => 'Hetzner has no API for dply to create keys. In the Hetzner Cloud Console, open your project → Object Storage → “Generate S3 credentials”, then paste the Access key and Secret below. Use the same region you pick here.',
            'key_console_url' => 'https://console.hetzner.com/',
            'pricing_url' => 'https://www.hetzner.com/storage/object-storage/',
            'pricing_note' => 'Billed by Hetzner on your own account. Base €4.99/mo includes 1 TB storage + 1 TB egress, then €4.99 per additional TB stored. No cold-storage tier.',
            'regions' => [
                'fsn1' => 'Falkenstein, Germany · fsn1',
                'nbg1' => 'Nuremberg, Germany · nbg1',
                'hel1' => 'Helsinki, Finland · hel1',
            ],
        ],

        'custom_s3' => [
            'label' => 'Custom S3-compatible',
            'endpoint_template' => '', // Operator supplies the endpoint directly.
            'provision' => false, // Attach-only — endpoints/quirks vary too much to create blind.
            'regions' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Freshly-minted key propagation retry
    |--------------------------------------------------------------------------
    |
    | When dply mints an S3 key via a provider API (DigitalOcean Spaces) it
    | isn't active on the S3 gateway for a few seconds. ObjectStorageBucketProvisioner
    | retries CreateBucket on InvalidAccessKeyId/SignatureDoesNotMatch this many
    | times (with this delay) before giving up — only when the caller flags the
    | keys as freshly minted, so operator-supplied keys still fail fast.
    |
    */
    'fresh_key_retry_attempts' => (int) env('OBJECT_STORAGE_FRESH_KEY_RETRY_ATTEMPTS', 6),
    'fresh_key_retry_delay_ms' => (int) env('OBJECT_STORAGE_FRESH_KEY_RETRY_DELAY_MS', 2500),

    /*
    | Cold-storage restore (thaw) for AWS Glacier Flexible / Deep Archive when a
    | backup in those classes is downloaded. `restore_available_days` = how long
    | the thawed copy stays downloadable; `restore_tier` = Standard|Bulk|Expedited
    | (Deep Archive does not support Expedited).
    */
    'restore_available_days' => (int) env('OBJECT_STORAGE_RESTORE_DAYS', 7),
    'restore_tier' => env('OBJECT_STORAGE_RESTORE_TIER', 'Standard'),

    /*
    | Shown wherever dply helps create/connect a bucket: storage is billed by
    | the provider on the customer's own account and dply adds no markup.
    */
    'no_cut_disclaimer' => 'Storage is billed by the provider directly on your own account — dply doesn’t add any fee or take a cut.',

];
