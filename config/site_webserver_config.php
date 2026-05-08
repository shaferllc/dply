<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site meta keys for the webserver-config apply banner
    |--------------------------------------------------------------------------
    | Mirrors the SSH-keys workspace banner pattern: small status fields live in
    | `site.meta`, the (potentially large) command transcript lives in cache keyed
    | by run_id with a short TTL so banner re-renders are cheap. Apply runs are
    | dispatched onto the queue; the banner polls and flips to completed/failed
    | when the worker finishes the job.
    */
    'meta_apply_run_id_key' => 'webserver_apply_run_id',
    'meta_apply_status_key' => 'webserver_apply_status',
    'meta_apply_started_at_key' => 'webserver_apply_started_at',
    'meta_apply_finished_at_key' => 'webserver_apply_finished_at',
    'meta_apply_error_key' => 'webserver_apply_error',
    // Kind slug for whichever job is currently writing the meta. Banner copy is
    // chosen by kind, so a wired-up SSL or systemd job shows the right label
    // instead of the generic "webserver config" wording.
    'meta_apply_kind_key' => 'site_apply_kind',

    'apply_output_cache_key_prefix' => 'site_webserver_apply_output:',
    'apply_output_cache_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Apply kinds
    |--------------------------------------------------------------------------
    | Each server-touching job claims one of these kinds when it writes the
    | site_apply_* meta state. The banner getter picks the labels here; jobs
    | use the slug via the WritesSiteApplyState trait.
    |
    | Each kind exposes:
    |   - running:   "Doing X to :host …"      (host placeholder = server name)
    |   - completed: "X done."
    |   - failed:    "X failed."
    |   - stale:     "X did not finish."       (used when run is past staleness threshold)
    */
    'apply_kinds' => [
        'webserver_config' => [
            'running' => 'Applying webserver config to :host …',
            'completed' => 'Webserver config applied.',
            'failed' => 'Webserver config apply failed.',
            'stale' => 'Webserver config apply did not finish.',
        ],
        'ssl' => [
            'running' => 'Issuing SSL certificate on :host …',
            'completed' => 'SSL certificate issued.',
            'failed' => 'SSL certificate issuance failed.',
            'stale' => 'SSL issuance did not finish.',
        ],
        'system_user' => [
            'running' => 'Updating system user on :host …',
            'completed' => 'System user updated.',
            'failed' => 'System user update failed.',
            'stale' => 'System user update did not finish.',
        ],
        'systemd' => [
            'running' => 'Updating systemd units on :host …',
            'completed' => 'Systemd units updated.',
            'failed' => 'Systemd unit update failed.',
            'stale' => 'Systemd unit update did not finish.',
        ],
        'permissions' => [
            'running' => 'Resetting site permissions on :host …',
            'completed' => 'Site permissions reset.',
            'failed' => 'Permissions reset failed.',
            'stale' => 'Permissions reset did not finish.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-running threshold
    |--------------------------------------------------------------------------
    | If meta still says status=running this many seconds after started_at, the
    | banner shows the run as failed/stale and `dismissWebserverApplyBanner` is
    | allowed to clear it. Guards against orphaned banners when the worker dies
    | mid-job (so a site doesn't get permanently stuck in "running").
    */
    'apply_stale_after_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | Output line cap stored in cache
    |--------------------------------------------------------------------------
    | Keeps the cache payload bounded for very chatty apply runs. Trims to the
    | last N lines so the failure tail is preserved.
    */
    'apply_output_max_lines' => 400,

];
