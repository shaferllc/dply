<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coming-soon gate
    |--------------------------------------------------------------------------
    | Redirect logged-out visitors to the marketing "coming soon" page.
    | COMING_SOON=true forces it on (even locally, for preview); =false turns it
    | fully off; unset falls back to the legacy behavior (on in any non-local
    | environment). See App\Http\Middleware\RedirectGuestsToComingSoon.
    */
    'coming_soon' => env('COMING_SOON') !== null
        ? filter_var(env('COMING_SOON'), FILTER_VALIDATE_BOOLEAN)
        : null,

    /*
    | IP allow-list for the coming-soon gate. These addresses (and any logged-in
    | user) see the FULL site; everyone else only sees the coming-soon page.
    | Supports IPv4, IPv6, and CIDR ranges. Sources are merged: the base list
    | below + the comma-separated COMING_SOON_ALLOWED_IPS env var + the
    | admin-managed rows (coming_soon_allowed_ips table).
    */
    'coming_soon_allowed_ips' => array_values(array_unique(array_filter(array_map(
        static fn ($v): string => trim((string) $v),
        array_merge(
            [
                // Base allow-list (operator addresses).
                '2600:1701:408:173e:28cc:b5fa:9fd3:c347',
                '66.10.105.85',
            ],
            explode(',', (string) env('COMING_SOON_ALLOWED_IPS', '')),
        )
    )))),

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
    | Provision auto-retry on transient failures
    |--------------------------------------------------------------------------
    | When true, a failed setup task whose output matches transient patterns
    | (apt fetch timeout, dpkg lock contention, network blip) reschedules itself with a
    | backoff up to MAX_AUTO_RETRY_ATTEMPTS. Default on — disable with
    | DPLY_AUTO_RETRY_ENABLED=false when iterating on the bash script locally.
    */
    'auto_retry_enabled' => filter_var(env('DPLY_AUTO_RETRY_ENABLED', true), FILTER_VALIDATE_BOOL),

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

    /*
    |--------------------------------------------------------------------------
    | Site scaffolding (Laravel + WordPress one-click installs)
    |--------------------------------------------------------------------------
    | Gates the new "scaffold a fresh app" branch of the Site Create wizard
    | plus the WordPress Site Settings section. Default off until the
    | back-end pipelines (PR 5–6) and journey UI (PR 7) ship; flips on once
    | the pipeline is reliable end-to-end.
    */
    'scaffold_v1_enabled' => filter_var(env('DPLY_SCAFFOLD_V1_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Choose-an-application flow (VM post-creation app picker)
    |--------------------------------------------------------------------------
    | Gates the new flow where a VM site is created bare (domain + server) in
    | STATUS_AWAITING_APP and the user then picks what runs on it (Git repo,
    | WordPress, Laravel, Statamic, static, blank) on a dedicated
    | sites.choose-app page. Default off; when off the existing import/scaffold
    | wizard remains the fallback. VM hosts only for now — container/serverless
    | keep their dedicated create flows. See docs/CHOOSE_APP_FLOW.md.
    */
    'choose_app_enabled' => filter_var(env('DPLY_CHOOSE_APP_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Edge: usage-based billing (pass-through + margin)
    |--------------------------------------------------------------------------
    |
    | When enabled, live Edge sites keep the flat platform fee (edge_cents in
    | config/subscription.php) plus metered delivery usage on top. Snapshots
    | are collected by `dply:edge:collect-usage` (scheduled daily).
    |
    | Unit rates are customer-facing and should embed margin over Cloudflare
    | list pricing. Per-site included allowances absorb typical small-site
    | traffic so the base fee covers quiet sites.
    */
    'edge' => [
        'usage_billing' => [
            'enabled' => filter_var(env('DPLY_EDGE_USAGE_BILLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'markup_percent' => (int) env('DPLY_EDGE_USAGE_MARKUP_PERCENT', 0),
            'requests_cents_per_million' => (int) env('DPLY_EDGE_USAGE_REQUESTS_CENTS_PER_MILLION', 30),
            'egress_cents_per_gb' => (int) env('DPLY_EDGE_USAGE_EGRESS_CENTS_PER_GB', 2),
            'r2_storage_cents_per_gb_month' => (int) env('DPLY_EDGE_USAGE_R2_STORAGE_CENTS_PER_GB_MONTH', 2),
            'r2_class_a_cents_per_million' => (int) env('DPLY_EDGE_USAGE_R2_CLASS_A_CENTS_PER_MILLION', 450),
            // Cloudflare R2 Class B (reads) list price is $0.36 / million = 36
            // cents. The previous default of 360 was a 10x typo that billed
            // customers ten times the real cost.
            'r2_class_b_cents_per_million' => (int) env('DPLY_EDGE_USAGE_R2_CLASS_B_CENTS_PER_MILLION', 36),
            'included_requests_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_REQUESTS_PER_SITE', 5_000_000),
            'included_egress_gb_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_EGRESS_GB_PER_SITE', 100),
            'included_r2_storage_gb_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_STORAGE_GB_PER_SITE', 5),
            // R2 operations included allowances — keep small sites at $0.
            // Class A = writes (PUT/POST/LIST/COPY); Class B = reads (GET/HEAD).
            // Cloudflare's free tier is 1M Class A + 10M Class B per month
            // org-wide; we allocate generous per-site allowances so a typical
            // static deploy never accrues ops charges.
            'included_r2_class_a_ops_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_CLASS_A_OPS_PER_SITE', 100_000),
            'included_r2_class_b_ops_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_CLASS_B_OPS_PER_SITE', 1_000_000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Serverless: usage-based billing for dply-managed functions
    |--------------------------------------------------------------------------
    |
    | Managed functions run on dply's own FaaS account (dply pays the provider),
    | so they keep the flat per-function fee (serverless_cents in
    | config/subscription.php) plus metered usage on top. BYO functions — where
    | the customer pays their own provider — are NOT metered here.
    |
    | DigitalOcean Functions has no usable per-function usage API, so v1 meters
    | INVOCATIONS rolled up from the operational function_invocations log by
    | `dply:serverless:collect-usage`. The per-function included allowance keeps
    | low-traffic functions covered by the flat fee. `gib_seconds_*` rates are
    | wired for future providers (Cloudflare/AWS) that report compute directly.
    |
    | Unit rates are customer-facing and embed margin over provider list
    | pricing; `markup_percent` applies an additional blanket markup.
    */
    'serverless' => [
        'usage_billing' => [
            'enabled' => filter_var(env('DPLY_SERVERLESS_USAGE_BILLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'markup_percent' => (int) env('DPLY_SERVERLESS_USAGE_MARKUP_PERCENT', 0),
            // $0.40 / million invocations after the included allowance. DO bills
            // bundled compute; this rate keeps margin while staying well under
            // hyperscaler per-request pricing.
            'invocations_cents_per_million' => (int) env('DPLY_SERVERLESS_USAGE_INVOCATIONS_CENTS_PER_MILLION', 40),
            // GiB-second rate for providers that report compute (Cloudflare/AWS).
            // DO leaves gib_seconds at 0, so this is dormant until those land.
            'gib_seconds_cents_per_100k' => (int) env('DPLY_SERVERLESS_USAGE_GIB_SECONDS_CENTS_PER_100K', 185),
            'included_invocations_per_function' => (int) env('DPLY_SERVERLESS_USAGE_INCLUDED_INVOCATIONS_PER_FUNCTION', 1_000_000),
            'included_gib_seconds_per_function' => (int) env('DPLY_SERVERLESS_USAGE_INCLUDED_GIB_SECONDS_PER_FUNCTION', 90_000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local workspace pruning
    |--------------------------------------------------------------------------
    |
    | Control-plane build scratch under storage/app accumulates and never self-
    | prunes: serverless build artifacts (one zip per deploy), per-site git
    | checkout caches, and task-runner temp. The scheduled command
    | `dply:prune-local-workspaces` removes entries older than these ages.
    */
    'quick_login_enabled' => filter_var(env('DPLY_QUICK_LOGIN_ENABLED', false), FILTER_VALIDATE_BOOL),

    'local_workspace_prune' => [
        'enabled' => filter_var(env('DPLY_LOCAL_WORKSPACE_PRUNE_ENABLED', true), FILTER_VALIDATE_BOOL),
        // Built artifact zips are byproducts once uploaded to the provider; keep
        // a short window for post-mortem on a failed deploy, then reclaim.
        'artifacts_max_age_hours' => max(1, (int) env('DPLY_LOCAL_ARTIFACTS_MAX_AGE_HOURS', 48)),
        // Git checkout caches speed up incremental redeploys; prune ones no
        // deploy has touched in a week (they re-clone on next use).
        'repositories_max_age_hours' => max(1, (int) env('DPLY_LOCAL_REPOSITORIES_MAX_AGE_HOURS', 168)),
        // Task-runner temp is short-lived scratch.
        'task_runner_max_age_hours' => max(1, (int) env('DPLY_LOCAL_TASK_RUNNER_MAX_AGE_HOURS', 24)),
    ],

];
