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
        // exit: ship to all orgs once we've had 5+ successful AWS provisions in prod
        'aws' => env('FEATURE_PROVIDER_AWS', true),
        // exit: ship to all orgs once Linode cost-catalog parity is verified
        'linode' => env('FEATURE_PROVIDER_LINODE', true),
        // exit: ship to all orgs once we've had 5+ successful Vultr provisions in prod
        'vultr' => env('FEATURE_PROVIDER_VULTR', true),
        // exit: ship once Fly.io machine provisioning is end-to-end green
        'fly_io' => env('FEATURE_PROVIDER_FLY_IO', false),
        // exit: ship after UpCloud SSH-key handshake is verified against a real account
        'upcloud' => env('FEATURE_PROVIDER_UPCLOUD', true),
        // exit: ship after Scaleway API token flow + cost catalog are validated
        'scaleway' => env('FEATURE_PROVIDER_SCALEWAY', true),
        // exit: bare-metal flow is materially different; keep gated until a paying customer asks
        'equinix_metal' => env('FEATURE_PROVIDER_EQUINIX_METAL', false),
        // exit: ship once container-on-AppRunner architecture lands per dply cloud memo
        'aws_app_runner' => env('FEATURE_PROVIDER_AWS_APP_RUNNER', false),
        // exit: keep gated indefinitely; EKS is enterprise-only positioning
        'aws_eks' => env('FEATURE_PROVIDER_AWS_EKS', false),
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
        'server_blueprint' => env('FEATURE_WORKSPACE_SERVER_BLUEPRINT', true),
        // exit: ship once server webserver diff + rollback validated on nginx + caddy
        'webserver_config_diff' => env('FEATURE_WORKSPACE_WEBSERVER_CONFIG_DIFF', true),
        // exit: ship once server maintenance suspend/resume validated on three VM hosts
        'server_maintenance' => env('FEATURE_WORKSPACE_SERVER_MAINTENANCE', true),
        // exit: ship once multi-node provisioning is end-to-end tested
        'cluster' => env('FEATURE_WORKSPACE_CLUSTER', false),
        // exit: ship once browser-SSH session auditing + RBAC are validated
        'console' => env('FEATURE_WORKSPACE_CONSOLE', false),
        // exit: ship once remote file-write atomic guarantees are reviewed; security surface
        'files' => env('FEATURE_WORKSPACE_FILES', false),
        // exit: ship when systemd inventory UI has been validated against three real OSes
        'services' => env('FEATURE_WORKSPACE_SERVICES', false),
        // exit: ship when system-user deletion policy is signed off (data loss risk)
        'system_users' => env('FEATURE_WORKSPACE_SYSTEM_USERS', false),
        // exit: ship as a paid-tier differentiator once findings UX is signed off
        'insights' => env('FEATURE_WORKSPACE_INSIGHTS', false),
        // exit: ship once Redis/Memcached provisioning has parity with the cache audit
        'caches' => env('FEATURE_WORKSPACE_CACHES', false),
        // exit: ship as the new scheduler experience once heartbeat ingest stabilizes
        'schedule' => env('FEATURE_WORKSPACE_SCHEDULE', false),
        // exit: ship once audit-log filtering UI is reviewed
        'activity' => env('FEATURE_WORKSPACE_ACTIVITY', false),
        // exit: ship once remote-script execution surface is reviewed (security risk)
        'run' => env('FEATURE_WORKSPACE_RUN', false),
        // exit: ship after per-deploy key lifecycle validated on three OSes
        'ephemeral_credentials' => env('FEATURE_WORKSPACE_EPHEMERAL_CREDENTIALS', true),
    ],

    /*
    | Whole non-workspace product surfaces. Each is a top-level route group.
    |
    | VM-only launch: cloud / edge / serverless are off by default; per-org
    | overrides (Admin Dashboard → Surface flags) let design partners and
    | internal dogfooders opt in without redeploying. Webhooks + scheduled
    | jobs stay live regardless of the flag — gating is UI/route-only.
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
    ],

    /*
    | App-wide kill switches. Scoped to null (not currentOrganization) —
    | access via Feature::for(null)->active('global.X') or the @feature
    | directive with the same name (resolver handles null-scope).
    */
    'global' => [
        // exit: flip to true on the day we charge real money; ALSO remove the dormant pricing-page gate
        'billing_enabled' => env('FEATURE_GLOBAL_BILLING_ENABLED', false),
        // exit: flip to true when closed beta opens to the public
        'signups_open' => env('FEATURE_GLOBAL_SIGNUPS_OPEN', false),
        // exit: kept indefinitely as an emergency switch; never retire
        'maintenance_mode' => env('FEATURE_GLOBAL_MAINTENANCE_MODE', false),
        // exit: ship when BYO redirect/cron/hook sync is validated on three OSes
        'byo_repo_config' => env('FEATURE_GLOBAL_BYO_REPO_CONFIG', false),
        // exit: ship after replay validated against password-protected previews
        'edge_deploy_replay' => env('FEATURE_GLOBAL_EDGE_DEPLOY_REPLAY', false),
        // exit: ship when LLM + heuristic triage validated across BYO + Edge failures
        'ops_copilot' => env('FEATURE_GLOBAL_OPS_COPILOT', false),
    ],

    /*
    | Tier B launch workflows — multi-engine setup wizards gated until
    | Cloud + Edge surfaces are enabled for the org.
    */
    'launch' => [
        // exit: ship when FullStackArchitecturePlanner handoffs are validated E2E
        'full_stack_wizard' => env('FEATURE_LAUNCH_FULL_STACK_WIZARD', false),
        // exit: ship when standby playbooks validated on hybrid + BYO + DNS cutover paths
        'standby_blueprint' => env('FEATURE_LAUNCH_STANDBY_BLUEPRINT', false),
    ],

];
