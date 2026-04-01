<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Run cron SSH actions as root first
    |--------------------------------------------------------------------------
    | When true, cron read/sync/run actions try root SSH first. This lets Dply
    | continue managing the deploy user's crontab even if the deploy user can no
    | longer log in directly with the stored key.
    */
    'use_root_ssh' => (bool) env('SERVER_CRON_USE_ROOT_SSH', true),

    /*
    |--------------------------------------------------------------------------
    | Retry cron SSH actions as deploy SSH user if root SSH fails
    |--------------------------------------------------------------------------
    */
    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_CRON_FALLBACK_TO_DEPLOY_SSH', true),

];
