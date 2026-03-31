<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Internal spike route (GET /internal/spike)
    |--------------------------------------------------------------------------
    |
    | Off by default in production. Set WORDPRESS_INTERNAL_SPIKE=true to expose the
    | JSON seam in staging, or rely on APP_ENV=testing (phpunit sets this).
    |
    */

    'internal_spike_enabled' => filter_var(env('WORDPRESS_INTERNAL_SPIKE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Control plane API (Bearer)
    |--------------------------------------------------------------------------
    */

    'api_token' => env('WORDPRESS_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Deploy API defaults (POST /api/wordpress/deploy)
    |--------------------------------------------------------------------------
    */

    'default_php_version' => env('WORDPRESS_DEFAULT_PHP_VERSION', '8.3'),

    'default_git_ref' => env('WORDPRESS_DEFAULT_GIT_REF', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Hosted provisioner (ADR-007)
    |--------------------------------------------------------------------------
    |
    | The deploy engine uses HostedWordpressProvisioner. `local` is the in-app
    | deterministic implementation; add an HTTP/SDK driver when the tenant fleet
    | API is available.
    |
    */

    'provisioner' => env('WORDPRESS_PROVISIONER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Mock WordPress version (local provisioner output only)
    |--------------------------------------------------------------------------
    */

    'mock_wordpress_version' => env('WORDPRESS_MOCK_WP_VERSION', '6.4.2'),

];
