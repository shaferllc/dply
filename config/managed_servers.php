<?php

return [
    /*
    |--------------------------------------------------------------------------
    | dply-managed servers (platform cloud account)
    |--------------------------------------------------------------------------
    |
    | When a server is created in "managed" mode dply provisions the VM on its
    | OWN cloud account (dply pays the provider) instead of the customer's
    | connected credential, and bills it all-in cost-plus.
    |
    | The managed backend is a SINGLE operator-configured provider — set
    | `provider` to the backend you run (hetzner or vultr) and configure that
    | block's platform token below. The managed create flow, catalog, and
    | teardown all read the active provider through
    | {@see App\Support\Servers\ServerHostingPlatformContext}. The managed option
    | is only offered when the active provider's `api_token` is set
    | (see ServerHostingPlatformContext::configured()).
    |
    | Mirrors the Edge / managed-serverless platform-context pattern.
    */
    'provider' => env('DPLY_MANAGED_PROVIDER', 'hetzner'),

    'hetzner' => [
        'api_token' => env('DPLY_MANAGED_HETZNER_API_TOKEN'),
        'default_region' => env('DPLY_MANAGED_HETZNER_REGION', 'fsn1'),
        'default_image' => env('DPLY_MANAGED_HETZNER_IMAGE', 'ubuntu-24.04'),
    ],

    /*
    | Beta blast-radius isolation. The free CX22s handed to beta orgs run in a
    | SEPARATE Hetzner project (its own API token) from Edge / production-managed
    | infra. A single abuser (crypto mining, spam, outbound DDoS from a free box)
    | can get a whole Hetzner project flagged or suspended — isolating beta turns
    | a catastrophic "every managed server down" incident into a contained
    | "beta project suspended" one. When the beta token is unset, beta managed
    | servers fall back to the primary `hetzner` project above (fine for local /
    | fake-cloud dev). Selected per-org by ServerHostingPlatformContext::forOrg().
    | Beta isolation is Hetzner-specific — when the managed provider is Vultr the
    | context falls through to the primary Vultr block.
    */
    'beta_hetzner' => [
        'api_token' => env('DPLY_MANAGED_BETA_HETZNER_API_TOKEN'),
        'default_region' => env('DPLY_MANAGED_BETA_HETZNER_REGION', 'fsn1'),
        'default_image' => env('DPLY_MANAGED_BETA_HETZNER_IMAGE', 'ubuntu-24.04'),
    ],

    /*
    | Platform Vultr account, used when `provider` is set to "vultr". default_image
    | is a Vultr os_id (2152 = Ubuntu 24.04 LTS).
    */
    'vultr' => [
        'api_token' => env('DPLY_MANAGED_VULTR_API_TOKEN'),
        'default_region' => env('DPLY_MANAGED_VULTR_REGION', 'ewr'),
        'default_image' => env('DPLY_MANAGED_VULTR_IMAGE', '2152'),
    ],

    /*
    | Curated per-provider catalogs dply offers as managed servers. We do NOT hit
    | the live provider catalog at create time (it needs the platform token and
    | would expose every size) — instead we offer a vetted shortlist per backend.
    | The slug must be a real provider size id (Hetzner server_type / Vultr plan);
    | raw monthly provider cost + markup live in config/subscription.php keyed by
    | the same slug. Regions are the locations the managed project operates in.
    */
    'catalogs' => [
        'hetzner' => [
            'regions' => [
                'fsn1' => 'Falkenstein, Germany',
                'nbg1' => 'Nuremberg, Germany',
                'hel1' => 'Helsinki, Finland',
                'ash' => 'Ashburn, VA (US East)',
                'hil' => 'Hillsboro, OR (US West)',
            ],
            'sizes' => [
                ['slug' => 'cx22', 'label' => 'CX22', 'vcpu' => 2, 'ram_gb' => 4, 'disk_gb' => 40],
                ['slug' => 'cx32', 'label' => 'CX32', 'vcpu' => 4, 'ram_gb' => 8, 'disk_gb' => 80],
                ['slug' => 'cx42', 'label' => 'CX42', 'vcpu' => 8, 'ram_gb' => 16, 'disk_gb' => 160],
                ['slug' => 'cx52', 'label' => 'CX52', 'vcpu' => 16, 'ram_gb' => 32, 'disk_gb' => 320],
            ],
        ],
        'vultr' => [
            'regions' => [
                'ewr' => 'New Jersey, US East',
                'sjc' => 'Silicon Valley, US West',
                'ord' => 'Chicago, US Central',
                'lhr' => 'London, UK',
                'fra' => 'Frankfurt, Germany',
                'nrt' => 'Tokyo, Japan',
            ],
            'sizes' => [
                ['slug' => 'vc2-1c-2gb', 'label' => '1 vCPU / 2 GB', 'vcpu' => 1, 'ram_gb' => 2, 'disk_gb' => 55],
                ['slug' => 'vc2-2c-4gb', 'label' => '2 vCPU / 4 GB', 'vcpu' => 2, 'ram_gb' => 4, 'disk_gb' => 80],
                ['slug' => 'vc2-4c-8gb', 'label' => '4 vCPU / 8 GB', 'vcpu' => 4, 'ram_gb' => 8, 'disk_gb' => 160],
                ['slug' => 'vc2-6c-16gb', 'label' => '6 vCPU / 16 GB', 'vcpu' => 6, 'ram_gb' => 16, 'disk_gb' => 320],
            ],
        ],
    ],
];
