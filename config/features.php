<?php

/*
|--------------------------------------------------------------------------
| Feature Flags
|--------------------------------------------------------------------------
|
| Default values for every Pennant feature flag. The hybrid resolver in
| FeatureServiceProvider returns a per-scope DB override if one exists,
| otherwise falls back to the value here.
|
| Naming: dot-namespaced singular domain + snake_case leaf. The auto-
| registrar in FeatureServiceProvider walks this array and registers
| every leaf as "{namespace}.{leaf}" via Feature::define().
|
| Adding a flag:
|   1. Add an entry below with an exit-criteria comment.
|   2. Reference it via @feature(), route middleware, Livewire mount(),
|      or Feature::active() — same string as the config key.
|
| Retiring a flag:
|   Open a "chore: retire feature flag X" PR that removes:
|     - the config entry below
|     - the Feature::define (auto, just by deletion)
|     - every @feature / middleware / mount() / Feature::active call site
|     - any WithFeatures trait entries in tests
|
*/

return [

    /*
    | Cloud providers. MVP ships DigitalOcean + Hetzner + Vultr + Linode
    | globally; every other provider is per-org gated for design partners.
    */
    'provider' => [
        // exit: keep on; flagship MVP provider — flag exists for per-org pause / emergency cutoff
        'digitalocean' => env('FEATURE_PROVIDER_DIGITALOCEAN', true),
        // exit: ship to all orgs once we've had 5+ successful AWS provisions in prod
        'aws' => env('FEATURE_PROVIDER_AWS', false),
        // exit: ship to all orgs once Linode cost-catalog parity is verified
        'linode' => env('FEATURE_PROVIDER_LINODE', true),
        // exit: ship to all orgs once we've had 5+ successful Vultr provisions in prod
        'vultr' => env('FEATURE_PROVIDER_VULTR', false),
        // exit: ship once Fly.io machine provisioning is end-to-end green
        'fly_io' => env('FEATURE_PROVIDER_FLY_IO', false),
        // exit: ship after UpCloud SSH-key handshake is verified against a real account
        'upcloud' => env('FEATURE_PROVIDER_UPCLOUD', false),
        // exit: ship after Scaleway API token flow + cost catalog are validated
        'scaleway' => env('FEATURE_PROVIDER_SCALEWAY', false),
        // exit: bare-metal flow is materially different; keep gated until a paying customer asks
        'equinix_metal' => env('FEATURE_PROVIDER_EQUINIX_METAL', false),
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
        'valkey' => env('FEATURE_CACHE_VALKEY', false),
        // exit: ship once Memcached install + connection snippet validated on three OSes
        'memcached' => env('FEATURE_CACHE_MEMCACHED', false),
        // exit: ship once KeyDB upstream distro coverage is validated end-to-end
        'keydb' => env('FEATURE_CACHE_KEYDB', false),
        // exit: ship once Dragonfly pinned-release install is validated on three OSes
        'dragonfly' => env('FEATURE_CACHE_DRAGONFLY', false),
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
        'release_hygiene' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE', false),
        // exit: ship alongside release hygiene GA; teaser only when release hygiene is off
        'release_hygiene_preview' => env('FEATURE_WORKSPACE_RELEASE_HYGIENE_PREVIEW', true),
        // exit: ship once daemon SLO panel validated against supervisor health on three VM stacks
        'daemon_slo' => env('FEATURE_WORKSPACE_DAEMON_SLO', true),
        // exit: ship once server cert inventory + bulk renew validated on three VM hosts
        'cert_inventory' => env('FEATURE_WORKSPACE_CERT_INVENTORY', true),
        // exit: ship once deploy window policy validated blocking/allowing deploy jobs
        'deploy_windows' => env('FEATURE_WORKSPACE_DEPLOY_WINDOWS', true),
        // exit: ship alongside deploy windows GA; teaser only when deploy windows is off
        'deploy_windows_preview' => env('FEATURE_WORKSPACE_DEPLOY_WINDOWS_PREVIEW', true),
        // exit: ship once SSH access graph validated against authorized_keys panel
        'ssh_access_graph' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH', true),
        // exit: ship alongside SSH access graph GA; teaser only when it is off
        'ssh_access_graph_preview' => env('FEATURE_WORKSPACE_SSH_ACCESS_GRAPH_PREVIEW', true),
        // exit: ship once time-boxed contractor SSH sessions validated with auto-revoke
        'ssh_sessions' => env('FEATURE_WORKSPACE_SSH_SESSIONS', true),
        // exit: ship once per-server cost card + right-size nudge validated against billing + metrics
        'server_cost' => env('FEATURE_WORKSPACE_SERVER_COST', true),
        // exit: ship once server-scoped redeploy-all + cert renew shortcut validated on three VM hosts
        'bulk_site_actions' => env('FEATURE_WORKSPACE_BULK_SITE_ACTIONS', true),
        // exit: ship once security digest scan validated on three VM hosts
        'security_digest' => env('FEATURE_WORKSPACE_SECURITY_DIGEST', false),
        // exit: ship alongside security digest GA; teaser only when security digest is off
        'security_digest_preview' => env('FEATURE_WORKSPACE_SECURITY_DIGEST_PREVIEW', true),
        // exit: ship once multi-node provisioning is end-to-end tested
        'cluster' => env('FEATURE_WORKSPACE_CLUSTER', true),
        // exit: ship once browser-SSH session auditing + RBAC are validated
        'console' => env('FEATURE_WORKSPACE_CONSOLE', false),
        // exit: ship alongside console GA; teaser only when console is off
        'console_preview' => env('FEATURE_WORKSPACE_CONSOLE_PREVIEW', true),

        // exit: ship once remote file-write atomic guarantees are reviewed; security surface
        'files' => env('FEATURE_WORKSPACE_FILES', false),
        // exit: ship alongside files GA; teaser only when files is off
        'files_preview' => env('FEATURE_WORKSPACE_FILES_PREVIEW', true),
        // exit: ship when systemd inventory UI has been validated against three real OSes
        'services' => env('FEATURE_WORKSPACE_SERVICES', true),
        // exit: ship when system-user deletion policy is signed off (data loss risk)
        'system_users' => env('FEATURE_WORKSPACE_SYSTEM_USERS', true),
        // exit: ship as a paid-tier differentiator once findings UX is signed off
        'insights' => env('FEATURE_WORKSPACE_INSIGHTS', false),
        // exit: ship alongside insights GA; teaser only when insights is off
        'insights_preview' => env('FEATURE_WORKSPACE_INSIGHTS_PREVIEW', true),
        // exit: ship once Redis/Memcached provisioning has parity with the cache audit
        'caches' => env('FEATURE_WORKSPACE_CACHES', true),
        // GA — remote Docker inspector (containers, images, volumes, networks, compose, maintenance)
        'docker' => env('FEATURE_WORKSPACE_DOCKER', true),
        // teaser only when docker is off
        'docker_preview' => env('FEATURE_WORKSPACE_DOCKER_PREVIEW', true),
        // exit: GA — server-scoped database + site-files backup runs and schedules
        'backups' => env('FEATURE_WORKSPACE_BACKUPS', false),
        // exit: ship alongside backups GA; teaser only when backups is off
        'backups_preview' => env('FEATURE_WORKSPACE_BACKUPS_PREVIEW', true),
        // exit: ship as the new scheduler experience once heartbeat ingest stabilizes
        'schedule' => env('FEATURE_WORKSPACE_SCHEDULE', true),
        // exit: ship once audit-log filtering UI is reviewed
        'activity' => env('FEATURE_WORKSPACE_ACTIVITY', true),
        // exit: ship once remote-script execution surface is reviewed (security risk)
        'run' => env('FEATURE_WORKSPACE_RUN', false),
        // exit: ship alongside run GA; teaser only when run is off
        'run_preview' => env('FEATURE_WORKSPACE_RUN_PREVIEW', true),

        // exit: ship after per-deploy key lifecycle validated on three OSes
        'ephemeral_credentials' => env('FEATURE_WORKSPACE_EPHEMERAL_CREDENTIALS', true),
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
        'cloud' => env('FEATURE_SURFACE_CLOUD', false),
        // GA 2026-05: cross-server views (Health, Deploys, Domains, EnvSearch)
        // ship as the org-wide ops counterpart to /infrastructure. Saved-view
        // persistence is a follow-up enhancement, not a launch gate.
        'fleet' => env('FEATURE_SURFACE_FLEET', true),
        // exit: ship after a curated v1 marketplace catalog is approved
        'marketplace' => env('FEATURE_SURFACE_MARKETPLACE', false),
        // exit: ship as the org-substitute UX for solo users with 3+ servers
        'projects' => env('FEATURE_SURFACE_PROJECTS', false),
        // exit: ship once one-off script execution has audit + rollback story
        'scripts' => env('FEATURE_SURFACE_SCRIPTS', false),
        // exit: ship as a standalone product launch with its own positioning
        'status_pages' => env('FEATURE_SURFACE_STATUS_PAGES', false),
        // exit: ship when Edge build → R2 → CF Worker loop is green in staging
        'edge' => env('FEATURE_SURFACE_EDGE', false),
        // exit: ship once OpenWhisk multi-language adapters + billing are GA
        'serverless' => env('FEATURE_SURFACE_SERVERLESS', false),
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
        // exit: kept indefinitely as an emergency switch; never retire
        'maintenance_mode' => env('FEATURE_GLOBAL_MAINTENANCE_MODE', false),
        // exit: ship when BYO redirect/cron/hook sync is validated on three OSes
        'byo_repo_config' => env('FEATURE_GLOBAL_BYO_REPO_CONFIG', true),
        // exit: ship after replay validated against password-protected previews
        'edge_deploy_replay' => env('FEATURE_GLOBAL_EDGE_DEPLOY_REPLAY', true),
        // exit: ship when Edge promote gate + waiver audit validated E2E
        'deploy_contract' => env('FEATURE_GLOBAL_DEPLOY_CONTRACT', true),
        // exit: ship when LLM + heuristic triage validated across BYO + Edge failures
        'ops_copilot' => env('FEATURE_GLOBAL_OPS_COPILOT', true),
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
