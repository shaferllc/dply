<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Placeholder DNS zones for scaffolded sites
    |--------------------------------------------------------------------------
    | When a site is scaffolded without a custom domain, dply assigns a
    | placeholder hostname like <slug>.ondply.io and creates the matching
    | A record in the chosen zone via the configured DNS provider.
    |
    | Each zone entry binds a registered domain (the apex) to:
    |   - provider:      'digitalocean' | 'cloudflare' (PR 8 ships DO only)
    |   - credential_id: the ProviderCredential id used to authenticate
    |                    against that DNS provider's API
    |
    | The default_zone is consulted first; if it's missing or no credential
    | is configured, the pipeline falls back to nip.io for a working URL
    | with no DNS infra at all (per Q12 fallback).
    */

    'zones' => array_filter([
        'ondply.io' => filled(env('DPLY_PLACEHOLDER_DO_CREDENTIAL_ID')) ? [
            'provider' => 'digitalocean',
            'credential_id' => env('DPLY_PLACEHOLDER_DO_CREDENTIAL_ID'),
        ] : null,
    ]),

    'default_zone' => env('DPLY_PLACEHOLDER_DEFAULT_ZONE', 'ondply.io'),

    /*
    |--------------------------------------------------------------------------
    | A-record TTL (seconds)
    |--------------------------------------------------------------------------
    | Q13: placeholder records get a low TTL so detaching the placeholder
    | (when a real domain attaches) doesn't keep stale answers cached.
    */

    'ttl' => 60,

];
