<?php

/**
 * Feature flags for cloud/API providers. Toggle env vars to roll out gradually.
 *
 * Keys match `provider_credentials.provider` and server create `form.type` (e.g. digitalocean, custom).
 */
return [
    'enabled' => [
        'digitalocean' => env('DPLY_SERVER_PROVIDER_DIGITALOCEAN', true), // https://www.digitalocean.com/
        'digitalocean_functions' => env('DPLY_SERVER_PROVIDER_DIGITALOCEAN_FUNCTIONS', true), // https://www.digitalocean.com/products/functions/
        'digitalocean_kubernetes' => env('DPLY_SERVER_PROVIDER_DIGITALOCEAN_KUBERNETES', true), // https://www.digitalocean.com/products/kubernetes/
        'hetzner' => env('DPLY_SERVER_PROVIDER_HETZNER', true), // https://www.hetzner.com/cloud
        'linode' => env('DPLY_SERVER_PROVIDER_LINODE', true), // https://www.linode.com/
        'vultr' => env('DPLY_SERVER_PROVIDER_VULTR', true), // https://www.vultr.com/
        'akamai' => env('DPLY_SERVER_PROVIDER_AKAMAI', false), // https://www.akamai.com/solutions/cloud-computing
        'scaleway' => env('DPLY_SERVER_PROVIDER_SCALEWAY', true), // https://www.scaleway.com/en/iaas/
        'upcloud' => env('DPLY_SERVER_PROVIDER_UPCLOUD', true), // https://upcloud.com/
        'equinix_metal' => env('DPLY_SERVER_PROVIDER_EQUINIX_METAL', false), // https://metal.equinix.com/
        'ovh' => env('DPLY_SERVER_PROVIDER_OVH', false), // https://www.ovhcloud.com/en/public-cloud/
        'rackspace' => env('DPLY_SERVER_PROVIDER_RACKSPACE', false), // https://www.rackspace.com/cloud
        'fly_io' => env('DPLY_SERVER_PROVIDER_FLY_IO', false), // https://fly.io/
        'render' => env('DPLY_SERVER_PROVIDER_RENDER', false), // https://render.com/
        'railway' => env('DPLY_SERVER_PROVIDER_RAILWAY', false), // https://railway.app/
        'coolify' => env('DPLY_SERVER_PROVIDER_COOLIFY', false), // https://coolify.io/
        'aws' => env('DPLY_SERVER_PROVIDER_AWS', true), // https://aws.amazon.com/ec2/
        'aws_app_runner' => env('DPLY_SERVER_PROVIDER_AWS_APP_RUNNER', false), // https://aws.amazon.com/apprunner/
        /** DNS / CDN — API token only (not a compute host in v1). */
        'cloudflare' => env('DPLY_SERVER_PROVIDER_CLOUDFLARE', false), // https://www.cloudflare.com/
        'gandi' => env('DPLY_SERVER_PROVIDER_GANDI', false), // https://www.gandi.net/
        'namecheap' => env('DPLY_SERVER_PROVIDER_NAMECHEAP', false), // https://www.namecheap.com/
        'vercel_dns' => env('DPLY_SERVER_PROVIDER_VERCEL_DNS', false), // https://vercel.com/docs/projects/domains
        'aws_lambda' => env('DPLY_SERVER_PROVIDER_AWS_LAMBDA', true), // https://aws.amazon.com/lambda/
        'ghcr' => env('DPLY_SERVER_PROVIDER_GHCR', false), // GitHub Container Registry — image pull creds for Cloud apps
        'aws_kubernetes' => env('DPLY_SERVER_PROVIDER_AWS_KUBERNETES', true), // https://aws.amazon.com/eks/
        'gcp' => env('DPLY_SERVER_PROVIDER_GCP', false), // https://cloud.google.com/compute
        'azure' => env('DPLY_SERVER_PROVIDER_AZURE', true), // https://azure.microsoft.com/en-us/products/virtual-machines/
        'oracle' => env('DPLY_SERVER_PROVIDER_ORACLE', false), // https://www.oracle.com/cloud/compute/
        'custom' => env('DPLY_SERVER_PROVIDER_CUSTOM', true), // Custom/manual server entry
        /** Inventory-import sources (not compute targets). dply reads existing fleets to migrate them. */
        'ploi' => env('DPLY_SERVER_PROVIDER_PLOI', false), // https://ploi.io/
        'forge' => env('DPLY_SERVER_PROVIDER_FORGE', false), // https://forge.laravel.com/

    ],
];
