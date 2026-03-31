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

];
