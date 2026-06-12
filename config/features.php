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
| "{namespace}.{leaf}". Config/env is the global default; explicit per-org
| DB rows override. No null-scope "platform default" rows — change globals
| via env or this file + config:clear (tests: config([...]) + flush cache).
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
        // exit: ship once container-on-AppRunner architecture lands per dply cloud memo
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
        // exit: ship once Valkey install + engine-switch validated on Ubuntu 22.04/24.04 + Debian 12
        'valkey' => env('FEATURE_CACHE_VALKEY', true),
        // exit: ship once Memcached install + connection snippet validated on three OSes
        'memcached' => env('FEATURE_CACHE_MEMCACHED', false),
        // exit: ship once KeyDB upstream distro coverage is validated end-to-end
        'keydb' => env('FEATURE_CACHE_KEYDB', false),
        // exit: ship once Dragonfly pinned-release install is validated on three OSes
        'dragonfly' => env('FEATURE_CACHE_DRAGONFLY', false),
    ],

    /*
    | Database engines offered for install on BYO servers. MySQL, PostgreSQL,
    | and SQLite are always available; the rest start as "coming soon" until
    | their install + operate path is validated. When a flag is off the engine
    | shows a Soon badge + teaser in the Databases workspace and MariaDB
    | variants are filtered out of the server-create database picker.
    */
    'database' => [
        // exit: ship once MariaDB install + MySQL-family workspace validated on three OSes
        'mariadb' => env('FEATURE_DATABASE_MARIADB', false),
        // exit: ship once MongoDB install + document DB workspace validated on three OSes
        'mongodb' => env('FEATURE_DATABASE_MONGODB', false),
        // exit: ship once ClickHouse install + OLAP workspace validated on three OSes
        'clickhouse' => env('FEATURE_DATABASE_CLICKHOUSE', false),
    ],

    /*
    | Server-workspace tabs that are NOT in the MVP 14. Each maps to a
    | Livewire component under app/Livewire/Servers/Workspace*.php.
    */
    'workspace' => [
        // exit: ship after promote cutover validated on three VM migrations
        'site_promote' => env('FEATURE_WORKSPACE_SITE_PROMOTE', true),
        // exit: ship once health cockpit validated against guest metrics on three OSes
        'health' => env('FEATURE_WORKSPACE_HEALTH', true),
        // exit: ship once blueprint capture + wizard apply validated on three VM stacks
        'server_blueprint' => env('FEATURE_WORKSPACE_SERVER_BLUEPRINT', false),
        // exit: ship alongside server blueprint GA; teaser only when blueprint is off
        'server_blueprint_preview' => env('FEATURE_WORKSPACE_SERVER_BLUEPRINT_PREVIEW', true),
        // exit: ship once server webserver diff + rollback validated on nginx + caddy
        'webserver_config_diff' => env('FEATURE_WORKSPACE_WEBSERVER_CONFIG_DIFF', true),
        // exit: ship once server maintenance suspend/resume validated on three VM hosts
        'server_maintenance' => env('FEATURE_WORKSPACE_SERVER_MAINTENANCE', true),
        // exit: ship alongside server maintenance GA; teaser only when server maintenance is off
        'server_maintenance_preview' => env('FEATURE_WORKSPACE_SERVER_MAINTENANCE_PREVIEW', true),
        // exit: ship once patch advisor rollup validated against inventory probe on three Debian/Ubuntu hosts
        'patch_advisor' => env('FEATURE_WORKSPACE_PATCH_ADVISOR', true),
        // exit: ship once release hygiene scan + prune template validated on three atomic VM stacks
        'release_hygiene' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE', true),
        // exit: ship alongside release hygiene GA; teaser only when release hygiene is off
        'release_hygiene_preview' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE_PREVIEW', false),
        // exit: ship once daemon SLO panel validated against supervisor health on three VM stacks
        'daemon_slo' => env('FEATURE_WORKSPACE_DAEMON_SLO', true),
        // exit: ship once server cert inventory + bulk renew validated on three VM hosts
        'cert_inventory' => env('FEATURE_WORKSPACE_CERT_INVENTORY', true),
        // exit: ship once deploy window policy validated blocking/allowing deploy jobs
        'deploy_windows' => env('FEATURE_WORKSPACE_DEPLOY_WINDOWS', true),
        // exit: ship alongside deploy windows GA; teaser only when deploy windows is off
        'deploy_windows_preview' => env('FEATURE_WORKSPACE_DEPLOY_WINDOWS_PREVIEW', false),
        // exit: ship once SSH access graph validated against authorized_keys panel
        'ssh_access_graph' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH', true),
        // exit: ship alongside SSH access graph GA; teaser only when it is off
        'ssh_access_graph_preview' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH_PREVIEW', false),
        // exit: ship once time-boxed contractor SSH sessions validated with auto-revoke
        'ssh_sessions' => env('FEATURE_WORKSPACE_SSH_SESSIONS', true),
        // exit: ship once per-server cost card + right-size nudge validated against billing + metrics
        'server_cost' => env('FEATURE_WORKSPACE_SERVER_COST', true),
        // exit: ship once server-scoped redeploy-all + cert renew shortcut validated on three VM hosts
        'bulk_site_actions' => env('FEATURE_WORKSPACE_BULK_SITE_ACTIONS', true),
        // exit: ship once security digest scan validated on three VM hosts
        'security_digest' => env('FEATURE_WORKSPACE_SECURITY_DIGEST', true),
        // exit: ship alongside security digest GA; teaser only when security digest is off
        'security_digest_preview' => env('FEATURE_WORKSPACE_SECURITY_DIGEST_PREVIEW', false),
        // exit: ship once multi-node provisioning is end-to-end tested
        'cluster' => env('FEATURE_WORKSPACE_CLUSTER', true),
        // exit: ship once browser-SSH session auditing + RBAC are validated
        'console' => env('FEATURE_WORKSPACE_CONSOLE', false),
        // exit: ship alongside console GA; teaser only when console is off
        'console_preview' => env('FEATURE_WORKSPACE_CONSOLE_PREVIEW', true),
        // exit: ship once server-scoped CLI reference + install UX are validated
        'cli' => env('FEATURE_WORKSPACE_CLI', false),
        // exit: ship alongside CLI GA; teaser only when CLI is off
        'cli_preview' => env('FEATURE_WORKSPACE_CLI_PREVIEW', true),

        // exit: ship once remote file-write atomic guarantees are reviewed; security surface
        'files' => env('FEATURE_WORKSPACE_FILES', true),
        // exit: ship alongside files GA; teaser only when files is off
        'files_preview' => env('FEATURE_WORKSPACE_FILES_PREVIEW', false),
        // exit: ship when systemd inventory UI has been validated against three real OSes
        'services' => env('FEATURE_WORKSPACE_SERVICES', true),
        // exit: ship when system-user deletion policy is signed off (data loss risk)
        'system_users' => env('FEATURE_WORKSPACE_SYSTEM_USERS', true),
        // exit: ship as a paid-tier differentiator once findings UX is signed off
        'insights' => env('FEATURE_WORKSPACE_INSIGHTS', true),
        // exit: ship alongside insights GA; teaser only when insights is off
        'insights_preview' => env('FEATURE_WORKSPACE_INSIGHTS_PREVIEW', false),
        // exit: ship once Redis/Memcached provisioning has parity with the cache audit
        'caches' => env('FEATURE_WORKSPACE_CACHES', true),
        // GA — remote Docker inspector (containers, images, volumes, networks, compose, maintenance)
        'docker' => true,
        // teaser only when docker is off
        'docker_preview' => true,
        // exit: GA — server-scoped database + site-files backup runs and schedules
        'backups' => env('FEATURE_WORKSPACE_BACKUPS', true),
        // exit: ship alongside backups GA; teaser only when backups is off
        'backups_preview' => env('FEATURE_WORKSPACE_BACKUPS_PREVIEW', false),
        // exit: ship as the new scheduler experience once heartbeat ingest stabilizes
        'schedule' => env('FEATURE_WORKSPACE_SCHEDULE', true),
        // exit: ship once audit-log filtering UI is reviewed
        'activity' => env('FEATURE_WORKSPACE_ACTIVITY', true),
        // exit: ship once remote-script execution surface is reviewed (security risk)
        'run' => env('FEATURE_WORKSPACE_RUN', false),
        // exit: ship alongside run GA; teaser only when run is off
        'run_preview' => env('FEATURE_WORKSPACE_RUN_PREVIEW', true),
        // exit: ship once attribution validated on 3 OS stacks + 2+ site fixtures; security review on SSH ps script
        'shared_host' => env('FEATURE_WORKSPACE_SHARED_HOST', true),
        // exit: ship alongside shared host GA; teaser only when shared host is off
        'shared_host_preview' => env('FEATURE_WORKSPACE_SHARED_HOST_PREVIEW', true),

        // exit: ship after per-deploy key lifecycle validated on three OSes
        'ephemeral_credentials' => env('FEATURE_WORKSPACE_EPHEMERAL_CREDENTIALS', true),
        // exit: ship once Cloudflare CDN attach + purge + metrics validated on three VM stacks
        'site_cdn' => env('FEATURE_WORKSPACE_SITE_CDN', false),
        // exit: ship alongside site CDN GA; teaser only when site CDN is off
        'site_cdn_preview' => env('FEATURE_WORKSPACE_SITE_CDN_PREVIEW', true),
        // exit: ship once per-site nginx/varnish/lscache apply loop validated on three stacks
        'site_caching' => env('FEATURE_WORKSPACE_SITE_CACHING', false),
        // exit: ship alongside site caching GA; teaser only when site caching is off
        'site_caching_preview' => env('FEATURE_WORKSPACE_SITE_CACHING_PREVIEW', true),
        // Visual (step-builder) deploy pipeline — gated as "coming soon" for now;
        // the simple text deploy-script editor is the live default. Flip
        // deploy_pipeline_visual on to bring the visual builder back.
        'deploy_pipeline_visual' => env('FEATURE_WORKSPACE_DEPLOY_PIPELINE_VISUAL', false),
        'deploy_pipeline_visual_preview' => env('FEATURE_WORKSPACE_DEPLOY_PIPELINE_VISUAL_PREVIEW', true),
        // Live: per-site Logs workspace (server logs scoped to one site — live
        // viewer + app-log stream). Real flag on, preview off.
        'site_logs' => env('FEATURE_WORKSPACE_SITE_LOGS', true),
        'site_logs_preview' => env('FEATURE_WORKSPACE_SITE_LOGS_PREVIEW', false),
        // Off by default: multi-backend "Backends" tab (load-balanced web
        // backends; the substrate under Rolling/Canary deploys). Provisions real
        // paid servers + an LB, and the path is not yet validated end-to-end, so
        // it stays gated until enabled per env/org.
        // exit: ship once add-backend → LB provisioning → rolling/canary verified on real infra
        'site_backends' => env('FEATURE_WORKSPACE_SITE_BACKENDS', false),
        'site_backends_preview' => env('FEATURE_WORKSPACE_SITE_BACKENDS_PREVIEW', false),
        // Live: per-site notifications page (channel × event subscriptions, integration
        // webhooks, webhook IP security) plus the Errors → Notifications tab. Real flag
        // on, preview off.
        'site_notifications' => env('FEATURE_WORKSPACE_SITE_NOTIFICATIONS', true),
        'site_notifications_preview' => env('FEATURE_WORKSPACE_SITE_NOTIFICATIONS_PREVIEW', false),
        // Live: uptime/SSL monitors with history, incidents and channel alerts.
        // Real flag on, preview off.
        'site_monitor' => env('FEATURE_WORKSPACE_SITE_MONITOR', true),
        'site_monitor_preview' => env('FEATURE_WORKSPACE_SITE_MONITOR_PREVIEW', false),
        'site_errors' => env('FEATURE_WORKSPACE_SITE_ERRORS', true),
        'site_errors_preview' => env('FEATURE_WORKSPACE_SITE_ERRORS_PREVIEW', false),
        // Live: per-site file browser (read + edit ≤1 MB + download ≤25 MB), hard-locked
        // to the site directory (see App\Livewire\Sites\Files::siteRoot). Real flag on,
        // preview off.
        'site_files' => env('FEATURE_WORKSPACE_SITE_FILES', true),
        'site_files_preview' => env('FEATURE_WORKSPACE_SITE_FILES_PREVIEW'),
        'site_cli' => env('FEATURE_WORKSPACE_SITE_CLI', false),
        'site_cli_preview' => env('FEATURE_WORKSPACE_SITE_CLI_PREVIEW', true),
        // Live: assign the Linux account that owns a VM-backed PHP site's files
        // and runs its PHP-FPM pool, plus reset-permissions over SSH. Real flag
        // on, preview off.
        'site_system_user' => env('FEATURE_WORKSPACE_SITE_SYSTEM_USER', true),
        'site_system_user_preview' => env('FEATURE_WORKSPACE_SITE_SYSTEM_USER_PREVIEW', false),
        // Routing sub-tabs — coming-soon teaser until each ships.
        'site_aliases' => env('FEATURE_WORKSPACE_SITE_ALIASES', false),
        'site_aliases_preview' => env('FEATURE_WORKSPACE_SITE_ALIASES_PREVIEW', true),
        'site_redirects' => env('FEATURE_WORKSPACE_SITE_REDIRECTS', false),
        'site_redirects_preview' => env('FEATURE_WORKSPACE_SITE_REDIRECTS_PREVIEW', false),
        'site_preview' => env('FEATURE_WORKSPACE_SITE_PREVIEW', false),
        'site_preview_preview' => env('FEATURE_WORKSPACE_SITE_PREVIEW_PREVIEW', true),
        'site_tenants' => env('FEATURE_WORKSPACE_SITE_TENANTS', false),
        'site_tenants_preview' => env('FEATURE_WORKSPACE_SITE_TENANTS_PREVIEW', true),
    ],

    /*
    | Whole non-workspace product surfaces. Each is a top-level route group.
    |
    | Defaults are on for internal/dogfood builds. Platform admin can set
    | platform-wide defaults (Pennant null scope) and per-org overrides.
    | Webhooks + scheduled jobs stay live regardless — gating is UI/route-only.
    */
    'surface' => [
        // exit: VM launch is dark; flip to true once container/cloud surface is GA
        'cloud' => false,
        // GA 2026-05: cross-server views (Health, Deploys, Domains, EnvSearch)
        // ship as the org-wide ops counterpart to /infrastructure. Saved-view
        // persistence is a follow-up enhancement, not a launch gate.
        'fleet' => env('FEATURE_SURFACE_FLEET', true),
        // exit: ship after a curated v1 marketplace catalog is approved
        'marketplace' => env('FEATURE_SURFACE_MARKETPLACE', false),
        // exit: ship as the org-substitute UX for solo users with 3+ servers
        'projects' => env('FEATURE_SURFACE_PROJECTS', true),
        // exit: ship once one-off script execution has audit + rollback story
        'scripts' => env('FEATURE_SURFACE_SCRIPTS', false),
        // exit: ship as a standalone product launch with its own positioning
        'status_pages' => env('FEATURE_SURFACE_STATUS_PAGES', false),
        // exit: ship when Edge build → R2 → CF Worker loop is green in staging
        'edge' => env('FEATURE_SURFACE_EDGE', false),
        // Gates the managed (dply-hosted) option of a site's broadcasting
        // binding — the billed Cloudflare relay path. BYO broadcasting stays
        // available regardless. There is no standalone /realtime surface.
        'realtime' => env('FEATURE_SURFACE_REALTIME', true),
        // exit: ship once OpenWhisk multi-language adapters + billing are GA
        'serverless' => false,
        // exit: offer the dply-managed serverless option (dply runs the function
        // on its own FaaS account, billed cost-plus) once platform namespace
        // credentials are bootstrapped. Falls back to BYO-only when off.
        'serverless_managed' => env('FEATURE_SURFACE_SERVERLESS_MANAGED', false),
        // exit: offer dply-managed servers (dply runs the VM on its own Hetzner
        // account, billed all-in cost-plus) once the platform Hetzner token is
        // configured and abuse/teardown safeguards are validated.
        'managed_servers' => env('FEATURE_SURFACE_MANAGED_SERVERS', false),
    ],

    /*
    | App-wide kill switches. Scoped to null (not currentOrganization) —
    | access via Feature::for(null)->active('global.X') or the @feature
    | directive with the same name (resolver handles null-scope).
    */
    'global' => [
        // exit: flip to true on the day we charge real money; ALSO remove the dormant pricing-page gate
        'billing_enabled' => env('FEATURE_GLOBAL_BILLING_ENABLED', true),
        // exit: flip to true when closed beta opens to the public
        'signups_open' => env('FEATURE_GLOBAL_SIGNUPS_OPEN', true),
        // exit: kept indefinitely as an emergency switch; never retire.
        // MUST default false — a true default means any fresh flag resolution
        // (purge / new scope / cache clear / seeder) silently 503s the whole site.
        'maintenance_mode' => env('FEATURE_GLOBAL_MAINTENANCE_MODE', false),
        // exit: ship when BYO redirect/cron/hook sync is validated on three OSes
        'byo_repo_config' => env('FEATURE_GLOBAL_BYO_REPO_CONFIG', false),
        // exit: ship after replay validated against password-protected previews
        'edge_deploy_replay' => env('FEATURE_GLOBAL_EDGE_DEPLOY_REPLAY', true),
        // exit: ship when Edge promote gate + waiver audit validated E2E
        'deploy_contract' => env('FEATURE_GLOBAL_DEPLOY_CONTRACT', true),
        // exit: ship when LLM + heuristic triage validated across BYO + Edge failures
        'ops_copilot' => env('FEATURE_GLOBAL_OPS_COPILOT', true),
        // exit: ship when platform LLM synthesis validated + rate limits tuned
        'ai_llm' => env('FEATURE_GLOBAL_AI_LLM', true),
        // exit: emergency hard stop for BYO VM create/deploy/webhooks; never retire
        'vm_enabled' => env('FEATURE_GLOBAL_VM_ENABLED', true),
        // exit: emergency pause for Edge build/deploy pipeline; never retire
        'edge_delivery_enabled' => env('FEATURE_GLOBAL_EDGE_DELIVERY_ENABLED', true),
    ],

    /*
    | Cloud + Edge surfaces are enabled for the org.
    */
    'launch' => [
        // exit: ship when FullStackArchitecturePlanner handoffs are validated E2E
        'full_stack_wizard' => env('FEATURE_LAUNCH_FULL_STACK_WIZARD', true),
        // exit: ship when standby playbooks validated on hybrid + BYO + DNS cutover paths
        'standby_blueprint' => env('FEATURE_LAUNCH_STANDBY_BLUEPRINT', true),
    ],

];
