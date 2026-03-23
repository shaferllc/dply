<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organization member cap (null = unlimited)
    |--------------------------------------------------------------------------
    | Counts active members plus non-expired pending invitations.
    | When Stripe seat billing is active, the effective cap is the lower of this
    | value and subscription seat quantity (see Organization::effectiveMemberSeatCap).
    */
    'max_organization_members' => env('DPLY_MAX_ORG_MEMBERS') !== null
        ? (int) env('DPLY_MAX_ORG_MEMBERS')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Site URL health checks (HTTPS against primary domain)
    |--------------------------------------------------------------------------
    */
    'site_health_check_enabled' => filter_var(env('DPLY_SITE_HEALTH_CHECK', true), FILTER_VALIDATE_BOOL),

    'deploy_notifications' => filter_var(env('DPLY_DEPLOY_NOTIFICATIONS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Deploy hook default timeout (per-hook override on site_deploy_hooks)
    |--------------------------------------------------------------------------
    */
    'default_deploy_hook_timeout_seconds' => max(30, min(3600, (int) env('DPLY_DEPLOY_HOOK_TIMEOUT', 900))),

    /*
    |--------------------------------------------------------------------------
    | Remote cleanup when a site is deleted (CleanupRemoteSiteArtifactsJob)
    |--------------------------------------------------------------------------
    */
    'delete_remote_repository_on_site_delete' => filter_var(env('DPLY_DELETE_REMOTE_REPO_ON_SITE_DELETE', false), FILTER_VALIDATE_BOOL),

    'delete_remote_certbot_certificate_on_site_delete' => filter_var(env('DPLY_DELETE_REMOTE_CERT_ON_SITE_DELETE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Deploy email digest (hourly flush via scheduler when > 0)
    |--------------------------------------------------------------------------
    */
    'deploy_digest_hours' => max(0, min(24, (int) env('DPLY_DEPLOY_DIGEST_HOURS', 0))),

    /*
    |--------------------------------------------------------------------------
    | API tokens: default TTL when expiry left blank (deploy scope only)
    |--------------------------------------------------------------------------
    */
    'api_token_deploy_default_ttl_days' => max(1, min(365, (int) env('DPLY_API_TOKEN_DEPLOY_TTL_DAYS', 14))),

];
