<?php

/**
 * Feature flags for cloud/API providers. Toggle env vars to roll out gradually.
 *
 * Keys match `provider_credentials.provider` and server create `form.type` (e.g. digitalocean, custom).
 */
return [
    'enabled' => [
        'digitalocean' => env('DPLY_SERVER_PROVIDER_DIGITALOCEAN', true),
        'hetzner' => env('DPLY_SERVER_PROVIDER_HETZNER', false),
        'linode' => env('DPLY_SERVER_PROVIDER_LINODE', false),
        'vultr' => env('DPLY_SERVER_PROVIDER_VULTR', false),
        'akamai' => env('DPLY_SERVER_PROVIDER_AKAMAI', false),
        'scaleway' => env('DPLY_SERVER_PROVIDER_SCALEWAY', false),
        'upcloud' => env('DPLY_SERVER_PROVIDER_UPCLOUD', false),
        'equinix_metal' => env('DPLY_SERVER_PROVIDER_EQUINIX_METAL', false),
        'ovh' => env('DPLY_SERVER_PROVIDER_OVH', false),
        'rackspace' => env('DPLY_SERVER_PROVIDER_RACKSPACE', false),
        'fly_io' => env('DPLY_SERVER_PROVIDER_FLY_IO', false),
        'render' => env('DPLY_SERVER_PROVIDER_RENDER', false),
        'railway' => env('DPLY_SERVER_PROVIDER_RAILWAY', false),
        'coolify' => env('DPLY_SERVER_PROVIDER_COOLIFY', false),
        'cap_rover' => env('DPLY_SERVER_PROVIDER_CAP_ROVER', false),
        'aws' => env('DPLY_SERVER_PROVIDER_AWS', false),
        'gcp' => env('DPLY_SERVER_PROVIDER_GCP', false),
        'azure' => env('DPLY_SERVER_PROVIDER_AZURE', false),
        'oracle' => env('DPLY_SERVER_PROVIDER_ORACLE', false),
        'custom' => env('DPLY_SERVER_PROVIDER_CUSTOM', true),
    ],
];
