<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bundled products (free tracely + Lookout for annual top-tier orgs)
    |--------------------------------------------------------------------------
    |
    | DARK by default — the whole perk is inert until BUNDLE_PRODUCTS_ENABLED is
    | true, matching how other billing features ship (LOOKOUT_BILLING_ENABLED,
    | SERVER_LOGS_BILLING_ENABLED). With it off, the synchronizer no-ops, no
    | `bundle.*` events fire, and no workspaces are provisioned or suspended.
    |
    | See docs/adr/bundled-products-sso.md.
    |
    */

    'enabled' => filter_var(env('BUNDLE_PRODUCTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Days an org's bundle stays SUSPENDED (data frozen, reversible) before it is
    // hard-purged. A re-qualification inside this window resumes instantly.
    'retention_days' => (int) env('BUNDLE_RETENTION_DAYS', 75),

    // tracely inbound provisioning webhook (Lookout is provisioned in-process).
    // Unset by default so the dispatcher lands dark even if the flag is flipped
    // before tracely's endpoint exists.
    'tracely' => [
        'webhook_url' => env('BUNDLE_TRACELY_WEBHOOK_URL', ''),
        'webhook_secret' => env('BUNDLE_TRACELY_WEBHOOK_SECRET', ''),
    ],

    // Shared service token for the pull entitlements API (the reconcile backstop
    // the products call). Unset → the endpoint 503s (dark), so it can't be probed
    // before a token exists.
    'entitlements_api_token' => env('BUNDLE_ENTITLEMENTS_API_TOKEN', ''),

    // OIDC / "Log in with dply" (Laravel Passport). Everything here is INERT until
    // `composer require laravel/passport` + `php artisan passport:install`; the
    // BundleSsoServiceProvider guards on class_exists(Passport) so absence of the
    // package is a clean no-op. tracely + Lookout are statically-registered
    // first-party clients — seed them with `dply:bundle:passport-clients`.
    'sso' => [
        'access_token_ttl_minutes' => (int) env('BUNDLE_SSO_ACCESS_TTL_MINUTES', 60),
        'refresh_token_ttl_days' => (int) env('BUNDLE_SSO_REFRESH_TTL_DAYS', 30),
        'clients' => [
            'tracely' => [
                'name' => 'tracely',
                'redirect' => env('BUNDLE_SSO_TRACELY_REDIRECT', ''),
            ],
            'lookout' => [
                'name' => 'Lookout',
                'redirect' => env('BUNDLE_SSO_LOOKOUT_REDIRECT', ''),
            ],
        ],
    ],

];
