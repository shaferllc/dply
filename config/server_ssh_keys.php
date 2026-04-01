<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server meta: disable authorized_keys writes
    |--------------------------------------------------------------------------
    | When true, sync jobs and UI return an error instead of writing remote files.
    */
    'meta_disable_sync_key' => 'disable_authorized_keys_sync',

    /*
    |--------------------------------------------------------------------------
    | Server meta: run sshd -t after successful sync
    |--------------------------------------------------------------------------
    */
    'meta_health_check_key' => 'ssh_sync_health_check',

    /*
    |--------------------------------------------------------------------------
    | Server meta: label template for new keys (placeholders: {name}, {user}, {hostname}, {date})
    |--------------------------------------------------------------------------
    */
    'meta_label_template_key' => 'ssh_key_label_template',

    /*
    |--------------------------------------------------------------------------
    | Organization server_site_preferences key: default label template
    |--------------------------------------------------------------------------
    | Used when the server has no ssh_key_label_template in meta. Server meta wins.
    */
    'org_site_preferences_label_template_key' => 'ssh_key_label_template',

    /*
    |--------------------------------------------------------------------------
    | Run authorized_keys sync over root SSH first
    |--------------------------------------------------------------------------
    | When true, TaskRunner tries root SSH first for authorized_keys sync tasks.
    | This mirrors system logs/manage behavior and can recover when the deploy
    | SSH user has lost access to its own authorized_keys file.
    */
    'use_root_ssh' => (bool) env('SERVER_SSH_KEYS_USE_ROOT_SSH', true),

    /*
    |--------------------------------------------------------------------------
    | Retry authorized_keys sync as deploy SSH user if root SSH fails
    |--------------------------------------------------------------------------
    */
    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_SSH_KEYS_FALLBACK_TO_DEPLOY_SSH', true),

];
