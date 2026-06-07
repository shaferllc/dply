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
    | Connection tiers
    |--------------------------------------------------------------------------
    | Each managed broadcasting app is billed per-tier. A tier maps a max
    | concurrent-connection cap (enforced as a hard cap at the Worker via the
    | KV `maxConnections` field) to a monthly price. peak_connections is still
    | captured per window (see the Worker's /stats endpoint) so usage is visible
    | for upgrade nudges. `default_tier` is what a freshly provisioned app gets.
    */
    'tiers' => [
        'starter' => ['label' => 'Starter', 'max_connections' => 5000, 'price_cents' => 1500],
        'growth' => ['label' => 'Growth', 'max_connections' => 25000, 'price_cents' => 4900],
        'scale' => ['label' => 'Scale', 'max_connections' => 100000, 'price_cents' => 14900],
    ],

    'default_tier' => env('DPLY_REALTIME_DEFAULT_TIER', 'starter'),

    /*
    |--------------------------------------------------------------------------
    | Plan defaults (legacy fallback)
    |--------------------------------------------------------------------------
    | Pre-tier callers still read these; they resolve to the default tier's
    | values so a single source of truth (the tier table above) stays canonical.
    */
    'plan' => [
        'price_cents' => (int) env('DPLY_REALTIME_PRICE_CENTS', 1500),
        'max_connections' => (int) env('DPLY_REALTIME_MAX_CONNECTIONS', 5000),
    ],

];
