<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake realtime (local / testing)
    |--------------------------------------------------------------------------
    | When enabled in an allowed environment, provisioning skips the real
    | Cloudflare API and records app credentials in the cache instead. The UI
    | and credentials still work end-to-end; only the live WebSocket relay is
    | absent (run `wrangler dev` in packages/realtime-worker to exercise it).
    | See FakeRealtimeBackend.
    */
    'fake' => [
        'enabled' => filter_var(env('DPLY_FAKE_REALTIME', env('DPLY_FAKE_EDGE', false)), FILTER_VALIDATE_BOOLEAN),
        'allowed_environments' => ['local', 'testing'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare (the dply-managed realtime Worker)
    |--------------------------------------------------------------------------
    | The realtime Worker (packages/realtime-worker) is deployed once to the
    | platform Cloudflare account. dply provisions per-app credentials by
    | writing them into the APPS KV namespace below — it never re-deploys the
    | Worker. `host` is the public hostname the Worker is routed on; clients
    | connect at wss://{host}/app/{appKey} and servers publish to
    | https://{host}/apps/{appId}/events.
    */
    'cloudflare' => [
        'account_id' => env('DPLY_REALTIME_CF_ACCOUNT_ID', env('DPLY_EDGE_CF_ACCOUNT_ID')),
        'api_token' => env('DPLY_REALTIME_CF_API_TOKEN', env('DPLY_EDGE_CF_API_TOKEN')),
        'kv_namespace_id' => env('DPLY_REALTIME_CF_KV_NAMESPACE_ID'),
    ],

    // Public hostname the realtime Worker answers on.
    'host' => env('DPLY_REALTIME_HOST', 'realtime.on-dply.site'),

    /*
    |--------------------------------------------------------------------------
    | Plan defaults
    |--------------------------------------------------------------------------
    | v1 ships a single flat tier. peak_connections is captured from day one
    | (see the Worker's /stats endpoint) so connection-based tiers can drop in
    | later without re-provisioning. `max_connections` is a soft guardrail
    | surfaced in the UI; v1 does not hard-cut at the Worker.
    */
    'plan' => [
        // $9.00 / app / month — see project_pricing_model + the realtime spec.
        'price_cents' => (int) env('DPLY_REALTIME_PRICE_CENTS', 900),
        'max_connections' => (int) env('DPLY_REALTIME_MAX_CONNECTIONS', 1000),
    ],

];
