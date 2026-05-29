<?php

/*
|--------------------------------------------------------------------------
| Platform admin — Pennant flags (product-line layout)
|--------------------------------------------------------------------------
|
| product_lines: admin UI + org override tabs grouped by product line.
| global_groups: cross-cutting app-wide kill switches on /admin/flags/global.
|
| Emergency keys (global.vm_enabled, global.edge_delivery_enabled) appear
| on their product-line pages; orgs cannot override them.
|
*/

$serverWorkspaceSections = [
    'Monitor & health' => [
        'workspace.health' => 'Health cockpit',
        'workspace.daemon_slo' => 'Daemon SLO',
        'workspace.cert_inventory' => 'Certificate inventory',
        'workspace.server_cost' => 'Server cost card',
        'workspace.security_digest' => 'Security digest',
    ],
    'Insights' => [
        'workspace.insights' => 'Full workspace',
        'workspace.insights_preview' => 'Coming soon preview',
    ],
    'Deploy & ops' => [
        'workspace.deploy_windows' => 'Deploy windows',
        'workspace.release_hygiene' => 'Release hygiene',
        'workspace.patch_advisor' => 'Patch advisor',
        'workspace.ephemeral_credentials' => 'Ephemeral deploy credentials',
        'workspace.run' => 'Run / saved commands',
        'workspace.bulk_site_actions' => 'Bulk site actions',
    ],
    'Access & security' => [
        'workspace.ssh_access_graph' => 'SSH access graph',
        'workspace.ssh_sessions' => 'Temporary SSH sessions',
        'workspace.system_users' => 'System users',
    ],
    'Files' => [
        'workspace.files' => 'Remote file browser',
        'workspace.files_preview' => 'Coming soon preview',
    ],
    'Console' => [
        'workspace.console' => 'Full workspace',
        'workspace.console_preview' => 'Coming soon preview',
    ],
    'Blueprint' => [
        'workspace.server_blueprint' => 'Capture & apply',
        'workspace.server_blueprint_preview' => 'Coming soon preview',
    ],
    'Advanced' => [
        'workspace.cluster' => 'Cluster',
        'workspace.webserver_config_diff' => 'Webserver config diff',
        'workspace.server_maintenance' => 'Server maintenance',
        'workspace.services' => 'System services',
        'workspace.caches' => 'Server caches',
        'workspace.docker' => 'Docker Engine',
        'workspace.schedule' => 'Schedule workspace',
        'workspace.activity' => 'Activity log tab',
    ],
];

return [

    'product_lines' => [
        'vm-servers' => [
            'title' => 'VM — Servers',
            'description' => 'Emergency VM hard stop, cloud providers, and server workspace capabilities.',
            'emergency' => [
                'global.vm_enabled' => 'Emergency: VM / BYO hard stop',
            ],
            'groups' => array_merge([
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
            ], $serverWorkspaceSections),
        ],
        'vm-sites' => [
            'title' => 'VM — Sites',
            'description' => 'Site-scoped workspace capabilities on BYO VMs.',
            'groups' => [
                'Site capabilities' => [
                    'workspace.site_promote' => 'Site promote',
                ],
            ],
        ],
        'cloud' => [
            'title' => 'Cloud',
            'description' => 'Managed container apps (DO App Platform, App Runner, dply Cloud).',
            'groups' => [
                'Surface' => [
                    'surface.cloud' => 'Cloud apps',
                ],
            ],
        ],
        'edge' => [
            'title' => 'Edge',
            'description' => 'Static/SSG Edge delivery, previews, and CDN pipeline.',
            'emergency' => [
                'global.edge_delivery_enabled' => 'Emergency: pause Edge delivery pipeline',
            ],
            'groups' => [
                'Edge surface' => [
                    'surface.edge' => 'Edge',
                ],
                'Delivery' => [
                    'global.edge_deploy_replay' => 'Edge shadow replay',
                ],
            ],
        ],
        'serverless' => [
            'title' => 'Serverless',
            'description' => 'Functions and serverless runtimes.',
            'groups' => [
                'Surface' => [
                    'surface.serverless' => 'Serverless',
                ],
            ],
        ],
        'platform' => [
            'title' => 'Platform',
            'description' => 'Cross-line org surfaces and launch workflows.',
            'groups' => [
                'Surfaces' => [
                    'surface.fleet' => 'Fleet ops views',
                    'surface.marketplace' => 'Marketplace',
                    'surface.projects' => 'Projects',
                    'surface.scripts' => 'Scripts',
                    'surface.status_pages' => 'Status pages',
                ],
                'Launch' => [
                    'launch.full_stack_wizard' => 'Full-stack launch wizard',
                    'launch.standby_blueprint' => 'Standby failover blueprints',
                ],
            ],
        ],
    ],

    'global_groups' => [
        'Billing & access' => [
            'global.billing_enabled' => 'Billing UI + cost observatory',
            'global.signups_open' => 'Public signups',
        ],
        'Product' => [
            'global.byo_repo_config' => 'dply.yaml BYO sync',
            'global.ops_copilot' => 'Ops Copilot (deploy triage)',
        ],
        'Operations' => [
            'global.maintenance_mode' => 'Maintenance mode',
        ],
    ],

    /*
    | Legacy platform-default slugs → product-line slug (for redirects).
    */
    'legacy_default_group_redirects' => [
        'providers' => 'vm-servers',
        'workspace' => 'vm-servers',
        'surfaces' => 'platform',
        'launch' => 'platform',
    ],

    'legacy_org_tab_redirects' => [
        'providers' => 'vm-servers',
        'workspace' => 'vm-servers',
        'surfaces' => 'platform',
        'launch' => 'platform',
    ],

    /*
    | Org-scoped Pennant keys that end users always resolve from the platform
    | default (null scope). Prevents per-org overrides from blocking a global
    | coming-soon teaser after an admin enables it platform-wide.
    */
    'platform_only_org_flags' => [
        'workspace.console_preview',
        'workspace.insights_preview',
        'workspace.server_blueprint_preview',
        'workspace.files_preview',
    ],

    /*
    | Parent feature → coming-soon preview flag. Admin UI renders these as one
    | grouped card (full feature toggle + nested preview toggle).
    */
    'feature_preview_pairs' => [
        'workspace.console' => 'workspace.console_preview',
        'workspace.insights' => 'workspace.insights_preview',
        'workspace.server_blueprint' => 'workspace.server_blueprint_preview',
        'workspace.files' => 'workspace.files_preview',
    ],

];
