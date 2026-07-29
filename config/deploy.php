<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Post-cutover HTTP health check (deploy validation gate)
    |--------------------------------------------------------------------------
    |
    | After an atomic deploy flips the `current` symlink and restarts FPM, the
    | AtomicDeployHealthChecker requests the site over HTTP from the box itself
    | and FAILS the deploy if the app renders a 5xx (e.g. a missing Vite
    | manifest, a boot fatal, a bad config) — something the pre-cutover TCP
    | resource probe cannot see. The deploy is only reported successful once a
    | real page renders a non-5xx response.
    |
    | Defaults are validate-on / rollback-on. Override globally here, or
    | per-site via meta:
    |   deploy_health_enabled        (bool)   opt a site out
    |   deploy_health_auto_rollback  (bool)   restore previous release on fail
    |   deploy_health_path           (string) path to probe (default "/")
    |   deploy_health_expect_status  (int)    require an exact status instead of
    |                                          the default "any non-5xx" gate
    |   deploy_health_scheme         (string) http|https (default http, follows
    |                                          redirects so TLS sites still work)
    |   deploy_health_attempts       (int)    retries before failing
    |   deploy_health_delay_ms       (int)    delay between attempts
    |
    */

    'health_check_default' => filter_var(env('DEPLOY_HEALTH_CHECK', true), FILTER_VALIDATE_BOOLEAN),

    'health_check_auto_rollback' => filter_var(env('DEPLOY_HEALTH_AUTO_ROLLBACK', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Post-cutover asset-integrity check
    |--------------------------------------------------------------------------
    |
    | Extends the health gate: after the homepage renders non-5xx, the checker
    | pulls the Vite build assets it references and asserts each returns 200 +
    | a non-HTML MIME. This catches the "stale release" failure a 5xx probe
    | can't — a 200 page whose hashed CSS/JS 404s to the branded fallback
    | (text/html), leaving the site unstyled while the deploy looks green
    | (typically an OPcache pin after an atomic swap). On a miss it flushes
    | OPcache and re-probes once before failing the deploy (→ auto-rollback).
    |
    | Default-on. Opt a site out via meta.deploy_asset_integrity_enabled=false.
    |
    */

    'asset_integrity_default' => filter_var(env('DEPLOY_ASSET_INTEGRITY_CHECK', true), FILTER_VALIDATE_BOOLEAN),

];
