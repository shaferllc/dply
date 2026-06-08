<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server OS image catalog
    |--------------------------------------------------------------------------
    |
    | Canonical OS images a user can pick from when provisioning a cloud VM
    | (the create-server wizard's "Operating system" step). Each entry maps a
    | provider-agnostic key (e.g. "ubuntu-24-04") to the slug each provider's
    | API expects, so the wizard can show one tidy list ("Ubuntu 24.04 LTS")
    | regardless of how the provider spells it.
    |
    | Providers without an entry for a given image simply don't offer it; the
    | provisioning job falls back to that provider's services.php default when
    | the chosen image has no slug for it (see App\Support\Servers\ServerImageCatalog).
    |
    | To add a provider to the picker, add its slug under each image below — no
    | other code changes are needed for the catalog to resolve it.
    |
    */

    'default' => 'ubuntu-24-04',

    'images' => [

        'ubuntu-24-04' => [
            'label' => 'Ubuntu 24.04 LTS',
            'family' => 'ubuntu',
            'slugs' => [
                'digitalocean' => 'ubuntu-24-04-x64',
                'hetzner' => 'ubuntu-24.04',
                'linode' => 'linode/ubuntu24.04',
            ],
        ],

        'ubuntu-22-04' => [
            'label' => 'Ubuntu 22.04 LTS',
            'family' => 'ubuntu',
            'slugs' => [
                'digitalocean' => 'ubuntu-22-04-x64',
                'hetzner' => 'ubuntu-22.04',
                'linode' => 'linode/ubuntu22.04',
            ],
        ],

        'ubuntu-20-04' => [
            'label' => 'Ubuntu 20.04 LTS',
            'family' => 'ubuntu',
            'slugs' => [
                'digitalocean' => 'ubuntu-20-04-x64',
                'hetzner' => 'ubuntu-20.04',
                'linode' => 'linode/ubuntu20.04',
            ],
        ],

        'debian-12' => [
            'label' => 'Debian 12 (Bookworm)',
            'family' => 'debian',
            'slugs' => [
                'digitalocean' => 'debian-12-x64',
                'hetzner' => 'debian-12',
                'linode' => 'linode/debian12',
            ],
        ],

        'debian-11' => [
            'label' => 'Debian 11 (Bullseye)',
            'family' => 'debian',
            'slugs' => [
                'digitalocean' => 'debian-11-x64',
                'hetzner' => 'debian-11',
                'linode' => 'linode/debian11',
            ],
        ],

    ],

];
