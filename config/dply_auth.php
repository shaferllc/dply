<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central identity (apps/dply-auth + Passport)
    |--------------------------------------------------------------------------
    |
    | When enabled, users can sign in via OAuth authorization code + PKCE
    | against the dply Auth deployment (e.g. https://auth.dply.io).
    | Register a first-party client in Passport and set id/secret here.
    |
    */

    'enabled' => (bool) env('DPLY_AUTH_ENABLED', false),

    'auth_url' => rtrim((string) env('DPLY_AUTH_URL', 'http://dply-auth.test'), '/'),

    'client_id' => env('DPLY_AUTH_CLIENT_ID'),

    'client_secret' => env('DPLY_AUTH_CLIENT_SECRET'),

    'redirect_uri' => env('DPLY_AUTH_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/callback',

];
