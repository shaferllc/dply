<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dply-owned testing / preview hostnames
|--------------------------------------------------------------------------
|
| Every managed preview URL is minted on one of these zones. The list lives
| here — not in .env — so adding a zone is a code change, not a deploy-time
| secret. DNS for VM testing hostnames is Cloudflare (platform token:
| CLOUDFLARE_KEY) with Namecheap as
| fallback. Edge and Serverless keep their own apexes.
|
| Tests (APP_ENV=testing) use local *.test apexes so the suite never talks
| to a public zone.
*/

$testing = env('APP_ENV') === 'testing';

return [

    'provider' => 'cloudflare',

    /*
     * THE Cloudflare token. One key, one account, one place to look.
     *
     * dply used to read four config paths for "the Cloudflare token" and take
     * whichever was non-empty first. Two were dead (config/edge.php does not
     * exist, so edge.cloudflare.api_token was always null; the serverless key
     * had no consumers at all) and the winner was services.cloudflare.key,
     * documented as the MAIL transport key even though nothing reads it for
     * mail any more. A correctly-scoped token could therefore lose to a stale
     * one in a variable nobody remembered setting.
     *
     * CLOUDFLARE_API_TOKEN is canonical. The older names still resolve so an
     * existing deployment keeps working, but they are deprecated — set
     * CLOUDFLARE_API_TOKEN and delete the rest.
     */
    'cloudflare_api_token' => env(
        'CLOUDFLARE_API_TOKEN',
        env('DPLY_TESTING_CF_API_TOKEN', env('CLOUDFLARE_KEY', env('CLOUDFLARE_API_KEY'))),
    ),

    'vm_apex' => $testing ? 'dply.test' : 'on-dply.cc',
    'edge_apex' => $testing ? 'edge.test' : 'on-dply.site',
    'serverless_apex' => $testing ? 'dply.test' : 'dply-serverless.cloud',

    'vm' => $testing ? ['dply.test'] : [
        'on-dply.cc',
        'on-dply.app',
        'on-dply.cloud',
        'on-dply.site',
        'on-dply.com',
        'on-dply.live',
        'on-dply.online',
        'dply.app',
        'dply.cc',
        'dply.host',
        'dply.ink',
        'dply.us',
        'dply.online',
        'dply.site',
        'dply.space',
    ],

    'edge' => $testing ? ['edge.test'] : [
        'on-dply.site',
    ],

    'serverless' => $testing ? ['dply.test'] : [
        'dply-serverless.cloud',
    ],

];
