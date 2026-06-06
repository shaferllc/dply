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
            'regions' => [
                'fsn1' => 'Falkenstein, Germany · fsn1',
                'nbg1' => 'Nuremberg, Germany · nbg1',
                'hel1' => 'Helsinki, Finland · hel1',
            ],
        ],

        'custom_s3' => [
            'label' => 'Custom S3-compatible',
            'endpoint_template' => '', // Operator supplies the endpoint directly.
            'regions' => [],
        ],

    ],

];
