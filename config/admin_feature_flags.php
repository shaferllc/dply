<?php

/*
|--------------------------------------------------------------------------
| Platform admin — Pennant flags
|--------------------------------------------------------------------------
|
| org_groups: per-org overrides on /admin (Pennant org scope).
| global_groups: app-wide kill switches (Pennant null scope, global.* only).
|
| Platform defaults for org-scoped flags reuse org_groups — the admin
| dashboard renders a second panel that toggles Feature::for(null) so new
| orgs inherit the value until they get an explicit org override.
|
*/

return [

    'org_groups' => [
        'Providers' => [
            'provider.aws' => 'AWS EC2',
            'provider.linode' => 'Linode',
            'provider.vultr' => 'Vultr',
            'provider.fly_io' => 'Fly.io',
            'provider.upcloud' => 'UpCloud',
            'provider.scaleway' => 'Scaleway',
            'provider.equinix_metal' => 'Equinix Metal',
            'provider.aws_app_runner' => 'AWS App Runner',
            'provider.aws_eks' => 'AWS EKS',
        ],
        'Surfaces' => [
            'surface.cloud' => 'Cloud apps',
            'surface.edge' => 'Edge',
            'surface.serverless' => 'Serverless',
            'surface.marketplace' => 'Marketplace',
            'surface.fleet' => 'Fleet ops views',
            'surface.projects' => 'Projects',
            'surface.scripts' => 'Scripts',
            'surface.status_pages' => 'Status pages',
        ],
        'Workspace' => [
            'workspace.site_promote' => 'Site promote',
            'workspace.health' => 'Health cockpit',
            'workspace.server_blueprint' => 'Server blueprint',
            'workspace.webserver_config_diff' => 'Webserver config diff',
            'workspace.server_maintenance' => 'Server maintenance',
            'workspace.patch_advisor' => 'Patch advisor',
            'workspace.release_hygiene' => 'Release hygiene',
            'workspace.daemon_slo' => 'Daemon SLO',
            'workspace.cert_inventory' => 'Certificate inventory',
            'workspace.deploy_windows' => 'Deploy windows',
            'workspace.ssh_access_graph' => 'SSH access graph',
            'workspace.ssh_sessions' => 'Temporary SSH sessions',
            'workspace.server_cost' => 'Server cost card',
            'workspace.security_digest' => 'Security digest',
            'workspace.ephemeral_credentials' => 'Ephemeral deploy credentials',
            'workspace.cluster' => 'Cluster',
            'workspace.console' => 'Browser console',
            'workspace.files' => 'Remote files',
            'workspace.services' => 'System services',
            'workspace.system_users' => 'System users',
            'workspace.insights' => 'Insights',
            'workspace.caches' => 'Server caches',
            'workspace.schedule' => 'Schedule workspace',
            'workspace.activity' => 'Activity log tab',
            'workspace.run' => 'Run / saved commands',
        ],
        'Launch' => [
            'launch.full_stack_wizard' => 'Full-stack launch wizard',
            'launch.standby_blueprint' => 'Standby failover blueprints',
        ],
    ],

    'global_groups' => [
        'Billing & access' => [
            'global.billing_enabled' => 'Billing UI + cost observatory',
            'global.signups_open' => 'Public signups',
        ],
        'Product' => [
            'global.byo_repo_config' => 'dply.yaml BYO sync',
            'global.edge_deploy_replay' => 'Edge shadow replay',
            'global.ops_copilot' => 'Ops Copilot (deploy triage)',
        ],
        'Operations' => [
            'global.maintenance_mode' => 'Maintenance mode',
        ],
    ],

];
