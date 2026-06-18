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

];
