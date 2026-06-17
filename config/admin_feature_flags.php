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
    ],
    'Security digest' => [
        'workspace.security_digest' => 'Security digest',
        'workspace.security_digest_preview' => 'Coming soon preview',
    ],
    'Insights' => [
        'workspace.insights' => 'Full workspace',
        'workspace.insights_preview' => 'Coming soon preview',
    ],
    'Maintenance' => [
        'workspace.server_maintenance' => 'Server maintenance',
        'workspace.server_maintenance_preview' => 'Coming soon preview',
    ],
    'Docker' => [
        'workspace.docker' => 'Docker Engine',
        'workspace.docker_preview' => 'Coming soon preview',
    ],
    'Backups' => [
        'workspace.backups' => 'Backups workspace',
        'workspace.backups_preview' => 'Coming soon preview',
    ],
    'Deploy & ops' => [
        'workspace.patch_advisor' => 'Patch advisor',
        'workspace.ephemeral_credentials' => 'Ephemeral deploy credentials',
        'workspace.bulk_site_actions' => 'Bulk site actions',
    ],
    'Release hygiene' => [
        'workspace.release_hygiene' => 'Release hygiene',
        'workspace.release_hygiene_preview' => 'Coming soon preview',
    ],
    'Shared Host Radar' => [
        'workspace.shared_host' => 'Shared Host Radar',
        'workspace.shared_host_preview' => 'Coming soon preview',
    ],
    'Run' => [
        'workspace.run' => 'Run / saved commands',
        'workspace.run_preview' => 'Coming soon preview',
    ],
    'Access & security' => [
        'workspace.ssh_access_graph' => 'Access graph',
        'workspace.ssh_access_graph_preview' => 'Coming soon preview',
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
    'CLI' => [
        'workspace.cli' => 'Server CLI reference',
        'workspace.cli_preview' => 'Coming soon preview',
    ],
    'Blueprint' => [
        'workspace.server_blueprint' => 'Capture & apply',
        'workspace.server_blueprint_preview' => 'Coming soon preview',
    ],
    'Advanced' => [
        'workspace.cluster' => 'Cluster',
        'workspace.webserver_config_diff' => 'Webserver config diff',
        'workspace.services' => 'System services',
        'workspace.caches' => 'Server caches',
        'workspace.schedule' => 'Schedule workspace',
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
                    'provider.digitalocean' => 'DigitalOcean',
                    'provider.hetzner' => 'Hetzner Cloud',
                    'provider.aws' => 'AWS EC2',
                    'provider.gcp' => 'Google Cloud',
                    'provider.linode' => 'Linode',
                    'provider.vultr' => 'Vultr',
                    'provider.azure' => 'Azure',
                    'provider.oracle' => 'Oracle Cloud',
                    'provider.upcloud' => 'UpCloud',
                    'provider.aws_app_runner' => 'AWS App Runner',
                    'provider.aws_eks' => 'AWS EKS',
                ],
                // Cache engines. Redis is always available (no flag). Each leaf
                // off = "coming soon": Soon badge + teaser in the Caches
                // workspace, hidden from the server-create cache picker.
                'Cache engines' => [
                    'cache.valkey' => 'Valkey',
                    'cache.memcached' => 'Memcached',
                    'cache.keydb' => 'KeyDB',
                    'cache.dragonfly' => 'Dragonfly',
                ],
                // Database engines. MySQL / PostgreSQL / SQLite are always
                // available (no flag). Each leaf off = "coming soon": Soon
                // badge + teaser in the Databases workspace, hidden from the
                // server-create database picker (MariaDB variants).
                'Database engines' => [
                    'database.mariadb' => 'MariaDB',
                    'database.mongodb' => 'MongoDB',
                    'database.clickhouse' => 'ClickHouse',
                ],
            ], $serverWorkspaceSections),
        ],
        'vm-sites' => [
            'title' => 'VM — Sites',
            'description' => 'Site-scoped workspace capabilities on BYO VMs.',
            'groups' => [
                'Site capabilities' => [
                    'workspace.site_promote' => 'Site promote',
                    'workspace.site_cdn' => 'Site CDN / Edge',
                    'workspace.site_cdn_preview' => 'Coming soon preview',
                    'workspace.site_caching' => 'Site caching',
                    'workspace.site_caching_preview' => 'Coming soon preview',
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
                    'global.deploy_contract' => 'Deploy contract (promote gate)',
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
            'global.ai_llm' => 'AI LLM synthesis (Copilot, Shared Host, Docs Ask)',
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
    | Parent feature → coming-soon preview flag. Admin UI renders these as one
    | grouped card; both the parent and the preview default come from
    | config/features.php and are overridable per org.
    */
    'feature_preview_pairs' => [
        'workspace.console' => 'workspace.console_preview',
        'workspace.cli' => 'workspace.cli_preview',
        'workspace.insights' => 'workspace.insights_preview',
        'workspace.server_blueprint' => 'workspace.server_blueprint_preview',
        'workspace.files' => 'workspace.files_preview',
        'workspace.run' => 'workspace.run_preview',
        'workspace.release_hygiene' => 'workspace.release_hygiene_preview',
        'workspace.security_digest' => 'workspace.security_digest_preview',
        'workspace.server_maintenance' => 'workspace.server_maintenance_preview',
        'workspace.docker' => 'workspace.docker_preview',
        'workspace.backups' => 'workspace.backups_preview',
        'workspace.ssh_access_graph' => 'workspace.ssh_access_graph_preview',
        'workspace.shared_host' => 'workspace.shared_host_preview',
        'workspace.site_cdn' => 'workspace.site_cdn_preview',
        'workspace.site_caching' => 'workspace.site_caching_preview',
    ],

];
