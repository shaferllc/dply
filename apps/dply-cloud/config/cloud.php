<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Internal spike route (GET /internal/spike)
    |--------------------------------------------------------------------------
    |
    | Off by default in production. Set CLOUD_INTERNAL_SPIKE=true to expose the
    | JSON seam in staging, or rely on APP_ENV=testing (phpunit sets this).
    |
    */

    'internal_spike_enabled' => filter_var(env('CLOUD_INTERNAL_SPIKE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Control plane API (Bearer)
    |--------------------------------------------------------------------------
    */

    'api_token' => env('CLOUD_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Deploy API defaults (POST /api/cloud/deploy)
    |--------------------------------------------------------------------------
    */

    'default_stack' => env('CLOUD_DEFAULT_STACK', 'php'),

    'default_git_ref' => env('CLOUD_DEFAULT_GIT_REF', 'main'),

];
