<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require verified email (dashboard and gated actions)
    |--------------------------------------------------------------------------
    | When false, unverified users are treated as verified for access control.
    | Defaults to off in the local environment; set DPLY_REQUIRE_EMAIL_VERIFICATION
    | to override explicitly (e.g. true locally to match production behavior).
    */
    'require_email_verification' => env('DPLY_REQUIRE_EMAIL_VERIFICATION') !== null
        ? filter_var(env('DPLY_REQUIRE_EMAIL_VERIFICATION'), FILTER_VALIDATE_BOOL)
        : env('APP_ENV', 'production') !== 'local',

    /*
    |--------------------------------------------------------------------------
    | Community / docs links (optional)
    |--------------------------------------------------------------------------
    | Used on profile for “contribute a translation” style links.
    */
    'community_github_url' => env('DPLY_COMMUNITY_GITHUB_URL'),

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

    /*
    |--------------------------------------------------------------------------
    | API tokens: require Pro subscription to create (profile / granular UI)
    |--------------------------------------------------------------------------
    | When true, only organizations on an active Pro Stripe price may create
    | new tokens from Settings → API keys. Revoking still works.
    */
    'api_tokens_require_paid_plan' => filter_var(env('DPLY_API_TOKENS_REQUIRE_PAID_PLAN', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Demo DigitalOcean flow (php artisan dply:demo-do-server)
    |--------------------------------------------------------------------------
    | Token is never stored here — use --token or DPLY_DEMO_DO_TOKEN / DIGITALOCEAN_TOKEN.
    |
    | Provisioning runs as demo_user_email and attaches the droplet to that user’s first
    | organization (by membership created_at), so you can watch the same org in the UI.
    | demo_org_slug is only used when --org-slug is omitted and the user belongs to no org yet
    | (e.g. CI), or when you pass --org-slug explicitly.
    */
    'demo_user_email' => env('DPLY_DEMO_USER_EMAIL', 'tom.shafer@gmail.com'),
    'demo_org_slug' => env('DPLY_DEMO_ORG_SLUG', 'dply-automated-demo'),
    'demo_do_region' => env('DPLY_DEMO_DO_REGION', 'nyc1'),
    'demo_do_size' => env('DPLY_DEMO_DO_SIZE', 's-1vcpu-1gb'),

    /*
    |--------------------------------------------------------------------------
    | Public control-plane URL for TaskRunner signed webhooks
    |--------------------------------------------------------------------------
    | When workers or cloud VMs must POST to your app (e.g. stack provision
    | callbacks) but APP_URL is internal (http://127.0.0.1), set this to the
    | HTTPS URL the machine can reach (tunnel, load balancer, etc.). Signed
    | webhook routes are generated with this root when set.
    */
    'public_app_url' => env('DPLY_PUBLIC_APP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Server removal: default scheduled deletion day offset
    |--------------------------------------------------------------------------
    | When scheduling server removal from the UI, the date picker defaults to
    | today plus this many days (user can change the date).
    */
    'server_scheduled_deletion_default_days' => max(1, min(365, (int) env('DPLY_SERVER_SCHEDULED_DELETION_DEFAULT_DAYS', 7))),

    /*
    |--------------------------------------------------------------------------
    | Server removal: notify organization owners and admins
    |--------------------------------------------------------------------------
    | When true, scheduling or completing server removal sends mail to org
    | members with owner or admin roles (see DeleteServerAction and Livewire
    | server removal flows).
    */
    'server_deletion_notify_org_admins' => filter_var(env('DPLY_SERVER_DELETION_NOTIFY_ADMINS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Server removal: optional documentation URL (checklist in remove modal)
    |--------------------------------------------------------------------------
    */
    'server_deletion_docs_url' => env('DPLY_SERVER_DELETION_DOCS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Supervisor (Daemons): scheduled health checks
    |--------------------------------------------------------------------------
    | When enabled, `dply:supervisor-check-health` SSHes to ready servers that
    | have active programs and stores a snapshot in `servers.meta.supervisor_health`.
    | Org owners/admins can receive mail when managed programs look unhealthy.
    */
    'supervisor_health_check_enabled' => filter_var(env('DPLY_SUPERVISOR_HEALTH_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),

    'supervisor_health_notify_org_admins' => filter_var(env('DPLY_SUPERVISOR_HEALTH_NOTIFY_ADMINS', true), FILTER_VALIDATE_BOOL),

];
