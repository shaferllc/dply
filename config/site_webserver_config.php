<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site meta keys for the webserver-config apply banner
    |--------------------------------------------------------------------------
    | Mirrors the SSH-keys workspace banner pattern: small status fields live in
    | `site.meta`, the (potentially large) command transcript lives in cache keyed
    | by run_id with a short TTL so banner re-renders are cheap. Apply runs are
    | currently dispatched synchronously (dispatchSync), so the banner shows the
    | last completed/failed run rather than streaming live state.
    */
    'meta_apply_run_id_key' => 'webserver_apply_run_id',
    'meta_apply_status_key' => 'webserver_apply_status',
    'meta_apply_started_at_key' => 'webserver_apply_started_at',
    'meta_apply_finished_at_key' => 'webserver_apply_finished_at',
    'meta_apply_error_key' => 'webserver_apply_error',

    'apply_output_cache_key_prefix' => 'site_webserver_apply_output:',
    'apply_output_cache_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Output line cap stored in cache
    |--------------------------------------------------------------------------
    | Keeps the cache payload bounded for very chatty apply runs. Trims to the
    | last N lines so the failure tail is preserved.
    */
    'apply_output_max_lines' => 400,

];
