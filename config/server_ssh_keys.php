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

    /*
    |--------------------------------------------------------------------------
    | Server meta keys for the in-flight sync run banner
    |--------------------------------------------------------------------------
    | The workspace banner shows live SSH output while sync is running. We track
    | per-server run state under these meta keys (cleared/refreshed each run).
    | The streaming output buffer itself lives in the application cache keyed by
    | run_id (TTL ~5 minutes after completion) so banner re-renders are cheap.
    */
    'meta_sync_run_id_key' => 'ssh_key_sync_run_id',
    'meta_sync_status_key' => 'ssh_key_sync_status',
    'meta_sync_started_at_key' => 'ssh_key_sync_started_at',
    'meta_sync_finished_at_key' => 'ssh_key_sync_finished_at',
    'meta_sync_error_key' => 'ssh_key_sync_error',
    'sync_output_cache_key_prefix' => 'ssh_key_sync_output:',
    'sync_output_cache_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Server meta keys for the in-flight drift-preview run
    |--------------------------------------------------------------------------
    | Drift previews use the same banner pattern as sync. The cache payload at
    | `<prefix><run_id>` carries both the streaming `lines` array AND the
    | structured `diff_result` so the workspace can re-hydrate without rerunning.
    */
    'meta_drift_run_id_key' => 'ssh_key_drift_run_id',
    'meta_drift_status_key' => 'ssh_key_drift_status',
    'meta_drift_started_at_key' => 'ssh_key_drift_started_at',
    'meta_drift_finished_at_key' => 'ssh_key_drift_finished_at',
    'meta_drift_error_key' => 'ssh_key_drift_error',
    'drift_output_cache_key_prefix' => 'ssh_key_drift_output:',

];
