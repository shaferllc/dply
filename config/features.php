<?php

/*
|--------------------------------------------------------------------------
| Feature flags (Pennant) — org-scoped product rollout
|--------------------------------------------------------------------------
|
| Mental model: this file answers ONE question — "Should this organization
| get this product capability?" It is NOT a catalog of every on/off switch
| in the app. Use the layer that matches the question:
|
|   Layer                         | Question                           | Where
|   ------------------------------|------------------------------------|------------------------------
|   features.php (here) + Pennant | Org gets this product/tab/engine?  | FEATURE_* env; admin org override on `features` table; scope = current org (`global.*` = platform kill switches, read config not DB)
|   server_providers.php          | Is provider integration in build?  | DPLY_SERVER_PROVIDER_* (catalog; custom has no provider.* flag)
|   ServerProviderGate            | Org can add creds / create server?   | catalog AND provider.* when mapped in PENNANT_FLAGS
|   server_workspace.php          | Global engine UI not ready yet?      | webserver_coming_soon / edge_proxy_coming_soon (not Pennant)
|   server_workspace.php nav      | Show sidebar row for this server?    | requires_any_tags, except_host_kinds, requires_min_sites (not Pennant)
|   subscription / billing        | Org within plan limits?            | Organization::canCreateSite(), SubscriptionPlanResolver
|   dply.php, edge.php, …         | Ops / runtime behavior?              | DPLY_* env (not product rollout)
|
| Pennant resolution: FeatureServiceProvider registers every leaf below as
| "{namespace}.{leaf}". Precedence (highest wins): explicit per-org row in
| `features` → platform override (feature_platform_overrides table, managed
| at /admin/flags/all) → config/env default here. Change globals from the
| admin UI, or via env + config:clear (tests: config([...]) + flush cache).
|
| Namespaces in this file:
|   surface.*   — whole product routes (Cloud, Edge, Fleet, …)
|   workspace.* — server-workspace pages + *_preview teasers
|   provider.*  — gradual VM provider rollout (plus server_providers catalog)
|   cache.* / database.* — per-engine install rollout (CacheEngineAvailability, DatabaseEngineAvailability)
|   global.*    — platform kill switches (vm_enabled, edge_delivery_enabled, …)
|   launch.*    — cross-product wizards
|
| Core BYO workspace (Overview, Sites, Metrics, Logs, Firewall, Cron, …) has
| no workspace.* flag — only roadmap/advanced tabs and previews are gated here.
|
| Adding a flag:
|   1. Add an entry below with an exit-criteria comment.
|   2. Wire @feature(), route middleware, Livewire RequiresFeature, or
|      Feature::active() — same string as "{namespace}.{leaf}".
|   3. If admin-toggleable: config/admin_feature_flags.php (+ preview pair if teaser).
|
| Retiring a flag: remove entry, all call sites, and WithFeatures/usesFeatures tests.
|
*/

return [

    /*
    | Beta bundle — NOT a flag namespace. The list of per-org Pennant overrides
    | applied at beta-invite redemption (see BetaInvitation::redeem). Curate
    | "what beta orgs get to see" here without touching redemption code. Global
    | defaults for these flags stay off, so non-beta orgs are unaffected.
    | FeatureServiceProvider skips this reserved key when registering flags.
    */
    'beta_bundle' => [
        'surface.managed_servers',
    ],

    /*
    | Cloud providers. MVP ships DigitalOcean + Hetzner + Linode globally;
    | Vultr, UpCloud, and AWS stay per-org gated for design partners.
    */
    'provider' => [
        // exit: keep on; flagship MVP provider — flag exists for per-org pause / emergency cutoff
        'digitalocean' => env('FEATURE_PROVIDER_DIGITALOCEAN', true),
        // exit: keep on; full BYO compute + Cloud DNS — flag exists for per-org pause / emergency cutoff
        'hetzner' => env('FEATURE_PROVIDER_HETZNER', true),
        // exit: ship to all orgs once we've had 5+ successful AWS provisions in prod
        'aws' => env('FEATURE_PROVIDER_AWS', false),
        // exit: DNS only (Cloud DNS); compute removed — per-org rollout via Pennant
        'gcp' => env('FEATURE_PROVIDER_GCP', false),
        // exit: keep on; full BYO compute + Linode DNS Manager — flag for per-org pause / emergency cutoff
        'linode' => env('FEATURE_PROVIDER_LINODE', true),
        // exit: full BYO compute + Vultr DNS — per-org rollout via Pennant
        'vultr' => env('FEATURE_PROVIDER_VULTR', true),
        // exit: full BYO compute + Azure DNS — per-org rollout via Pennant
        'azure' => env('FEATURE_PROVIDER_AZURE', false),
        // exit: full BYO compute on OCI — per-org rollout via Pennant
        'oracle' => env('FEATURE_PROVIDER_ORACLE', false),
        // exit: ship after UpCloud SSH-key handshake is verified against a real account
        'upcloud' => env('FEATURE_PROVIDER_UPCLOUD', false),
        // exit: dogfood with DPLY_SERVER_PROVIDER_AWS_APP_RUNNER=true + this flag; App Runner
        // web path is live (image + GitHub source via connection ARN). Workers/deploy-tasks stay DO-only.
        'aws_app_runner' => env('FEATURE_PROVIDER_AWS_APP_RUNNER', false),
        // exit: keep gated indefinitely; EKS is enterprise-only positioning
        'aws_eks' => env('FEATURE_PROVIDER_AWS_EKS', false),
    ],

    /*
    | Cache engines offered for install on BYO servers. Redis is always
    | available; the rest start as "coming soon" until their install +
    | operate path is validated. When a flag is off the engine shows a
    | Soon badge + teaser in the Caches workspace and is filtered out of
    | the server-create cache picker. Resolved per-org by the hybrid
    | resolver, so platform admin can flip them on per-org or platform-wide
    | from /admin/flags — same pattern as the workspace coming-soon previews.
    */
    'cache' => [
        'valkey' => env('FEATURE_CACHE_VALKEY', true),
        'memcached' => env('FEATURE_CACHE_MEMCACHED', false),
        'keydb' => env('FEATURE_CACHE_KEYDB', false),
        'dragonfly' => env('FEATURE_CACHE_DRAGONFLY', false),
    ],

    /*
    | Database engines offered for install on BYO servers. MySQL, PostgreSQL,
    | and SQLite are always available; the engines below start as "coming
    | soon" until their install + operate path is validated. When a flag is
    | off the engine shows a Soon badge + teaser in the Databases workspace
    | and is filtered out of the server-create database picker. Resolved
    | per-org by the hybrid resolver, so platform admin can flip them on
    | per-org or platform-wide from /admin/flags. Gating keys are consumed by
    | App\Support\Servers\DatabaseEngineAvailability (`database.{engine}`).
    */
    'database' => [
        'mariadb' => env('FEATURE_DATABASE_MARIADB', false),
        'mongodb' => env('FEATURE_DATABASE_MONGODB', false),
        'clickhouse' => env('FEATURE_DATABASE_CLICKHOUSE', true),
    ],


    /*
    | Server-workspace tabs that are NOT in the MVP 14. Each maps to a
    | Livewire component under app/Livewire/Servers/Workspace*.php.
    */
    'workspace' => [
        'site_promote' => env('FEATURE_WORKSPACE_SITE_PROMOTE', true),
        'health' => env('FEATURE_WORKSPACE_HEALTH', true),
        'server_blueprint' => env('FEATURE_WORKSPACE_SERVER_BLUEPRINT', false),
        'server_blueprint_preview' => env('FEATURE_WORKSPACE_SERVER_BLUEPRINT_PREVIEW', true),
        'webserver_config_diff' => env('FEATURE_WORKSPACE_WEBSERVER_CONFIG_DIFF', true),
        'server_maintenance' => env('FEATURE_WORKSPACE_SERVER_MAINTENANCE', true),
        'server_maintenance_preview' => env('FEATURE_WORKSPACE_SERVER_MAINTENANCE_PREVIEW', true),
        'patch_advisor' => env('FEATURE_WORKSPACE_PATCH_ADVISOR', true),
        'release_hygiene' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE', true),
        'release_hygiene_preview' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE_PREVIEW', false),
        'daemon_slo' => env('FEATURE_WORKSPACE_DAEMON_SLO', true),
        'cert_inventory' => env('FEATURE_WORKSPACE_CERT_INVENTORY', true),
        'ssh_access_graph' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH', true),
        'ssh_access_graph_preview' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH_PREVIEW', false),
        'ssh_sessions' => env('FEATURE_WORKSPACE_SSH_SESSIONS', true),
        'server_cost' => env('FEATURE_WORKSPACE_SERVER_COST', true),
        'bulk_site_actions' => env('FEATURE_WORKSPACE_BULK_SITE_ACTIONS', true),
        'security_digest' => env('FEATURE_WORKSPACE_SECURITY_DIGEST', true),
        'security_digest_preview' => env('FEATURE_WORKSPACE_SECURITY_DIGEST_PREVIEW', false),
        'cluster' => env('FEATURE_WORKSPACE_CLUSTER', true),
        'console' => env('FEATURE_WORKSPACE_CONSOLE', true),
        'console_preview' => env('FEATURE_WORKSPACE_CONSOLE_PREVIEW', false),
        'cli' => env('FEATURE_WORKSPACE_CLI', true),
        'cli_preview' => env('FEATURE_WORKSPACE_CLI_PREVIEW', false),

        'files' => env('FEATURE_WORKSPACE_FILES', true),
        'files_preview' => env('FEATURE_WORKSPACE_FILES_PREVIEW', false),
        'services' => env('FEATURE_WORKSPACE_SERVICES', true),
        'system_users' => env('FEATURE_WORKSPACE_SYSTEM_USERS', true),
        'insights' => env('FEATURE_WORKSPACE_INSIGHTS', true),
        'insights_preview' => env('FEATURE_WORKSPACE_INSIGHTS_PREVIEW', false),
        'caches' => env('FEATURE_WORKSPACE_CACHES', true),
        'docker' => true,
        'docker_preview' => true,
        'backups' => env('FEATURE_WORKSPACE_BACKUPS', true),
        'backups_preview' => env('FEATURE_WORKSPACE_BACKUPS_PREVIEW', false),
        'schedule' => env('FEATURE_WORKSPACE_SCHEDULE', true),
        'run' => env('FEATURE_WORKSPACE_RUN', true),
        'run_preview' => env('FEATURE_WORKSPACE_RUN_PREVIEW', true),
        'shared_host' => env('FEATURE_WORKSPACE_SHARED_HOST', true),
        'shared_host_preview' => env('FEATURE_WORKSPACE_SHARED_HOST_PREVIEW', true),

        'ephemeral_credentials' => env('FEATURE_WORKSPACE_EPHEMERAL_CREDENTIALS', true),
        // GA: the CDN / Edge workspace is on by default. Like site_caching the
        // flag only reveals the surface — no site gets proxied until an
        // operator enables it there with a Cloudflare credential.
        'site_cdn' => env('FEATURE_WORKSPACE_SITE_CDN', true),
        'site_cdn_preview' => env('FEATURE_WORKSPACE_SITE_CDN_PREVIEW', false),
        // GA: the Caching workspace is on by default. The flag only reveals the
        // surface — page caching stays off for every site until an operator
        // toggles it there, so shipping this changes no site's behaviour.
        'site_caching' => env('FEATURE_WORKSPACE_SITE_CACHING', true),
        'site_caching_preview' => env('FEATURE_WORKSPACE_SITE_CACHING_PREVIEW', false),
        'deploy_pipeline_visual' => env('FEATURE_WORKSPACE_DEPLOY_PIPELINE_VISUAL', true),
        'deploy_pipeline_visual_preview' => env('FEATURE_WORKSPACE_DEPLOY_PIPELINE_VISUAL_PREVIEW', false),
        'site_logs' => env('FEATURE_WORKSPACE_SITE_LOGS', true),
        'site_logs_preview' => env('FEATURE_WORKSPACE_SITE_LOGS_PREVIEW', false),
        'site_backends' => env('FEATURE_WORKSPACE_SITE_BACKENDS', false),
        'site_backends_preview' => env('FEATURE_WORKSPACE_SITE_BACKENDS_PREVIEW', false),
        'site_notifications' => env('FEATURE_WORKSPACE_SITE_NOTIFICATIONS', true),
        'site_notifications_preview' => env('FEATURE_WORKSPACE_SITE_NOTIFICATIONS_PREVIEW', false),
        'site_monitor' => env('FEATURE_WORKSPACE_SITE_MONITOR', true),
        'site_monitor_preview' => env('FEATURE_WORKSPACE_SITE_MONITOR_PREVIEW', false),
        'site_errors' => env('FEATURE_WORKSPACE_SITE_ERRORS', true),
        'site_errors_preview' => env('FEATURE_WORKSPACE_SITE_ERRORS_PREVIEW', false),
        'site_files' => env('FEATURE_WORKSPACE_SITE_FILES', true),
        'site_files_preview' => env('FEATURE_WORKSPACE_SITE_FILES_PREVIEW'),
        'site_cli' => env('FEATURE_WORKSPACE_SITE_CLI', true),
        'site_cli_preview' => env('FEATURE_WORKSPACE_SITE_CLI_PREVIEW', false),
        'site_system_user' => env('FEATURE_WORKSPACE_SITE_SYSTEM_USER', true),
        'site_system_user_preview' => env('FEATURE_WORKSPACE_SITE_SYSTEM_USER_PREVIEW', false),
        'site_aliases' => env('FEATURE_WORKSPACE_SITE_ALIASES', true),
        'site_aliases_preview' => env('FEATURE_WORKSPACE_SITE_ALIASES_PREVIEW', false),
        'site_redirects' => env('FEATURE_WORKSPACE_SITE_REDIRECTS', false),
        'site_redirects_preview' => env('FEATURE_WORKSPACE_SITE_REDIRECTS_PREVIEW', false),
        'site_preview' => env('FEATURE_WORKSPACE_SITE_PREVIEW', true),
        'site_preview_preview' => env('FEATURE_WORKSPACE_SITE_PREVIEW_PREVIEW', false),
        'site_tenants' => env('FEATURE_WORKSPACE_SITE_TENANTS', true),
        'site_tenants_preview' => env('FEATURE_WORKSPACE_SITE_TENANTS_PREVIEW', false),
    ],

    /*
    | Whole non-workspace product surfaces. Each is a top-level route group.
    |
    | Defaults are on for internal/dogfood builds. Platform admin can set
    | platform-wide defaults (Pennant null scope) and per-org overrides.
    | Webhooks + scheduled jobs stay live regardless — gating is UI/route-only.
    */
    'surface' => [

        'cloud' => env('FEATURE_SURFACE_CLOUD', true),
        'fleet' => env('FEATURE_SURFACE_FLEET', true),
        'marketplace' => env('FEATURE_SURFACE_MARKETPLACE', true),
        'projects' => env('FEATURE_SURFACE_PROJECTS', true),
        'scripts' => env('FEATURE_SURFACE_SCRIPTS', true),
        'status_pages' => env('FEATURE_SURFACE_STATUS_PAGES', true),
        'edge' => env('FEATURE_SURFACE_EDGE', true),
        'realtime' => env('FEATURE_SURFACE_REALTIME', true),
        'serverless' => env('FEATURE_SURFACE_SERVERLESS', true),
        'serverless_managed' => env('FEATURE_SURFACE_SERVERLESS_MANAGED', true),
        'managed_servers' => env('FEATURE_SURFACE_MANAGED_SERVERS', true),
    ],

    /*
    | App-wide kill switches. Scoped to null (not currentOrganization) —
    | access via Feature::for(null)->active('global.X') or the @feature
    | directive with the same name (resolver handles null-scope).
    */
    'global' => [
        'billing_enabled' => env('FEATURE_GLOBAL_BILLING_ENABLED', true),
        'signups_open' => env('FEATURE_GLOBAL_SIGNUPS_OPEN', true),

        // Be careful with this flag.
        'maintenance_mode' => env('FEATURE_GLOBAL_MAINTENANCE_MODE', false),
        'byo_repo_config' => env('FEATURE_GLOBAL_BYO_REPO_CONFIG', true),
        'edge_deploy_replay' => env('FEATURE_GLOBAL_EDGE_DEPLOY_REPLAY', true),
        'deploy_contract' => env('FEATURE_GLOBAL_DEPLOY_CONTRACT', true),
        'ops_copilot' => env('FEATURE_GLOBAL_OPS_COPILOT', true),
        'ai_llm' => env('FEATURE_GLOBAL_AI_LLM', false),
        'vm_enabled' => env('FEATURE_GLOBAL_VM_ENABLED', true),
        'edge_delivery_enabled' => env('FEATURE_GLOBAL_EDGE_DELIVERY_ENABLED', true),
    ],

    /*
    | Cloud + Edge surfaces are enabled for the org.
    */
    'launch' => [
        'full_stack_wizard' => env('FEATURE_LAUNCH_FULL_STACK_WIZARD', true),
        'standby_blueprint' => env('FEATURE_LAUNCH_STANDBY_BLUEPRINT', true),
    ],

];
