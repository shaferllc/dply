<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Internal spike route (GET /internal/spike)
    |--------------------------------------------------------------------------
    |
    | Off by default in production. Set EDGE_INTERNAL_SPIKE=true to expose the
    | JSON seam in staging, or rely on APP_ENV=testing (phpunit sets this).
    |
    */

    'internal_spike_enabled' => filter_var(env('EDGE_INTERNAL_SPIKE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Control plane API (Bearer)
    |--------------------------------------------------------------------------
    */

    'api_token' => env('EDGE_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Deploy API defaults (POST /api/edge/deploy)
    |--------------------------------------------------------------------------
    */

    'default_framework' => env('EDGE_DEFAULT_FRAMEWORK', 'next'),

    'default_git_ref' => env('EDGE_DEFAULT_GIT_REF', 'main'),

];
