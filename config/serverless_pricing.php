<?php

/*
 * Estimated monthly cost (USD) of the DigitalOcean resources a serverless
 * function provisions. These are estimates surfaced upfront in the UI so an
 * operator knows the cost before clicking "provision".
 *
 * DigitalOcean bills the underlying clusters directly, and its prices can
 * change — the UI always labels these "estimated" and "billed by
 * DigitalOcean", never as an exact dply charge.
 */
return [
    // Managed Database cluster — monthly USD by DigitalOcean size slug.
    'database' => [
        'db-s-1vcpu-1gb' => 15,
        'db-s-1vcpu-2gb' => 30,
        'db-s-2vcpu-4gb' => 60,
    ],

    // Managed Redis cluster — monthly USD by DigitalOcean size slug.
    'cache' => [
        'db-s-1vcpu-1gb' => 15,
        'db-s-1vcpu-2gb' => 30,
        'db-s-2vcpu-4gb' => 60,
    ],
];
