<?php

/**
 * Feature flags for cloud/API providers. Toggle env vars to roll out gradually.
 *
 * Keys match `provider_credentials.provider` and server create `form.type` (e.g. digitalocean, custom).
 */
return [
    'enabled' => [
        'digitalocean' => true, // https://www.digitalocean.com/
        'digitalocean_functions' => true, // https://www.digitalocean.com/products/functions/
        'digitalocean_kubernetes' => true, // https://www.digitalocean.com/products/kubernetes/
        'hetzner' => true, // https://www.hetzner.com/cloud
        'linode' => true, // https://www.linode.com/
        'vultr' => true, // https://www.vultr.com/
        'upcloud' => true, // https://upcloud.com/

        'ovh' => false, // https://www.ovhcloud.com/en/public-cloud/ — off for now; shown as coming soon

        'aws' => true, // https://aws.amazon.com/ec2/
        'aws_app_runner' => false, // https://aws.amazon.com/apprunner/
        'cloudflare' => true, // https://www.cloudflare.com/ — DNS + CDN (no compute)
        'gandi' => false, // https://www.gandi.net/
        'namecheap' => false, // https://www.namecheap.com/
        'vercel_dns' => false, // https://vercel.com/docs/projects/domains — DNS only
        'aws_lambda' => true, // https://aws.amazon.com/lambda/
        'ghcr' => false, // GitHub Container Registry — image pull creds for Cloud apps
        'aws_kubernetes' => true, // https://aws.amazon.com/eks/
        'gcp' => false, // DNS only (Cloud DNS); compute removed
        'azure' => true, // https://azure.microsoft.com/en-us/products/virtual-machines/
        'custom' => true, // Custom/manual server entry

        /** Inventory-import sources (not compute targets). dply reads existing fleets to migrate them. */
        'ploi' => false, // https://ploi.io/
        'forge' => false, // https://forge.laravel.com/

    ],
];
