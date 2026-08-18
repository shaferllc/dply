<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tier-2 5xx auto-capture sweeper
    |--------------------------------------------------------------------------
    |
    | Every managed PHP-FPM site stamps each request with a reference id and logs
    | `status=<code>` to its per-pool access log (see SitePhpFpmPoolConfigBuilder).
    | The Tier-2 sweeper greps that log for 5xx responses and records each as an
    | `http_5xx` ErrorEvent (carrying the reference), so application 500s surface
    | on the Errors tab on their own — no longer only resolvable by hand via the
    | Tier-1 "paste a reference" lookup. See docs/SERVER_ERROR_CODES.md.
    */
    'sweep_enabled' => filter_var(env('DPLY_5XX_SWEEP_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | How far back each sweep reads the access log. Kept a little above the
    | dispatch cadence so a missed cycle (worker hiccup) still gets covered on the
    | next run without re-scanning the whole file.
    */
    'sweep_lookback_minutes' => max(2, min(180, (int) env('DPLY_5XX_SWEEP_LOOKBACK_MINUTES', 15))),

    /*
    | Hard cap on how many 5xx access-log lines a single sweep ingests per site,
    | so a site in a hot crash loop can't mint thousands of events (or stream an
    | unbounded log back over SSH) in one pass. Oldest-within-window are dropped;
    | the count is logged so truncation is never silent.
    */
    'sweep_max_per_site' => max(10, min(2000, (int) env('DPLY_5XX_SWEEP_MAX_PER_SITE', 200))),

    /*
    |--------------------------------------------------------------------------
    | Intercept 5xx with the branded error page (platform default)
    |--------------------------------------------------------------------------
    |
    | When true, every managed webserver config replaces a site's 5xx responses
    | with dply's branded "temporarily unavailable" page (carrying a reference id
    | for the Errors tab). When false — the default — dply does NOT intercept:
    | the app renders its own error pages (Laravel's 500/503, the framework debug
    | page when APP_DEBUG is on, or the webserver's own 502/504 when the upstream
    | is down). Each site can still override this from its Settings tab; the
    | per-site choice (sites.meta.expose_server_errors) wins over this default.
    */
    'intercept_5xx_by_default' => filter_var(env('DPLY_INTERCEPT_5XX_PAGES', false), FILTER_VALIDATE_BOOL),

];
