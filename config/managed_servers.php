<?php

return [
    /*
    |--------------------------------------------------------------------------
    | dply-managed servers (platform Hetzner account)
    |--------------------------------------------------------------------------
    |
    | When a server is created in "managed" mode dply provisions the VM on its
    | OWN Hetzner Cloud project (dply pays Hetzner) instead of the customer's
    | connected credential, and bills it all-in cost-plus. These are the
    | platform credentials for that project; the managed option is only offered
    | when `api_token` is set (see ServerHostingPlatformContext::configured()).
    |
    | Mirrors the Edge / managed-serverless platform-context pattern.
    */
    'hetzner' => [
        'api_token' => env('DPLY_MANAGED_HETZNER_API_TOKEN'),
        'default_region' => env('DPLY_MANAGED_HETZNER_REGION', 'fsn1'),
        'default_image' => env('DPLY_MANAGED_HETZNER_IMAGE', 'ubuntu-24.04'),
    ],

    /*
    | Curated set of Hetzner sizes dply offers as managed servers. We do NOT hit
    | the live Hetzner catalog at create time (it needs the platform token and
    | would expose every size) — instead we offer a vetted shortlist. The slug
    | must be a real Hetzner server_type name; raw monthly provider cost +
    | markup live in config/subscription.php keyed by the same slug.
    |
    | Regions are the Hetzner locations the managed project operates in.
    */
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
];
