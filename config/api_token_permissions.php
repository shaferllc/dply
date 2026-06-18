<?php

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\ApiToken;

/**
 * Single source of truth for API token ability strings.
 *
 * - UI categories + labels: categories
 * - Organization settings “simple scope” presets: presets
 * - Deployer role API/runtime cap (must be subset of catalog): deployer_api_allowlist
 * - HTTP API v1 route middleware: http_route_abilities (values must exist in catalog or *)
 *
 * The MCP server (routes/ai.php → App\Mcp) reuses these SAME abilities — each tool
 * declares the ability it requires and AbstractDplyTool enforces it via
 * $token->allows(), so an existing token (read/deploy/ops/full preset, or the
 * deployer allowlist) works unchanged over MCP. Tool → ability map:
 *   list_sites / get_site / list_site_workers / list_site_schedules
 *     / list_deployments / get_deployment / get_operation_status
 *     / get_site_env ..................................................... sites.read
 *   list_servers ........................................................ servers.read
 *   deploy_site ......................................................... sites.deploy
 *   set_site_env_var / delete_site_env_var / push_site_env .............. sites.write
 *   list_site_databases ................................................. database.read
 *   create_site_database ................................................ database.write
 * Later PRs add domains (sites.read/sites.write), SSL (certificates.read/write),
 * and maintenance/basic-auth (sites.write / auth_users.*) tools.
 *
 * @see ApiToken::tokenAllowsAbility()
 * @see AbstractDplyTool
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Presets (organization → API tokens simple scopes)
    |--------------------------------------------------------------------------
    */
    'presets' => [
        // 'servers.deploy' was removed when the legacy deploy_command
        // column was dropped — the underlying API endpoint no longer
        // exists. Existing tokens that hold the ability remain valid
        // tokens, the ability just no-ops.
        'read' => ['servers.read', 'sites.read', 'insights.read', 'projects.read'],
        'deploy' => ['servers.read', 'sites.read', 'sites.deploy', 'projects.read', 'projects.deploy'],
        'ops' => ['servers.read', 'sites.read', 'sites.deploy', 'commands.run', 'projects.read', 'projects.deploy'],
        'full' => ['*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployer role: abilities allowed at runtime (API + tokenAllowsAbility)
    |--------------------------------------------------------------------------
    */
    'deployer_api_allowlist' => [
        'account.read',
        'account.write',
        'servers.read',
        'sites.read',
        'sites.deploy',
        'system_users.read',
        'system_users.write',
        'projects.read',
        'projects.deploy',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API v1 — ability checked per route (keys are informational)
    |--------------------------------------------------------------------------
    */
    'http_route_abilities' => [
        'servers.index' => 'servers.read',
        'servers.run_command' => 'commands.run',
        'servers.log_shipping.show' => 'servers.read',
        'servers.log_shipping.enable' => 'commands.run',
        'servers.log_shipping.resync' => 'commands.run',
        'servers.log_shipping.disable' => 'commands.run',
        'sites.index' => 'sites.read',
        'sites.show' => 'sites.read',
        'sites.update' => 'sites.write',
        'sites.deploy' => 'sites.deploy',
        'sites.deployments' => 'sites.read',
        'sites.deployment_show' => 'sites.read',
        'sites.workers' => 'sites.read',
        'sites.schedules' => 'sites.read',
        'sites.errors' => 'sites.read',
        'sites.uptime' => 'sites.read',
        'sites.basic_auth' => 'auth_users.read',
        'sites.basic_auth_write' => 'auth_users.write',
        'sites.ssl' => 'certificates.read',
        'sites.domains' => 'sites.read',
        'sites.domains_write' => 'sites.write',
        'sites.databases' => 'database.read',
        'sites.commits' => 'sites.read',
        'sites.system_user' => 'system_users.read',
        'firewall.show' => 'network.read',
        'firewall.apply' => 'network.write',
        'firewall.bundled_apply' => 'network.write',
        'firewall.template_apply' => 'network.write',
        'insights.server_findings' => 'insights.read',
        'insights.org_summary' => 'insights.read',
        'imports.migrations_index' => 'imports.read',
        'imports.migrations_show' => 'imports.read',

        'servers.system_users.index' => 'system_users.read',
        'servers.system_users.sync' => 'system_users.write',
        'servers.system_users.store' => 'system_users.write',
        'servers.system_users.update' => 'system_users.write',
        'servers.system_users.destroy' => 'system_users.delete',

        'account.show' => 'account.read',
        'account.organizations' => 'account.read',
        'account.projects' => 'projects.read',
        'account.sessions' => 'account.read',
        'account.sessions_destroy' => 'account.write',

        'billing.show' => 'billing.read',
        'billing.breakdown' => 'billing.read',
        'billing.invoices' => 'billing.read',

        'projects.index' => 'projects.read',
        'projects.show' => 'projects.read',
        'projects.health' => 'projects.read',
        'projects.members_index' => 'projects.read',
        'projects.deploys_index' => 'projects.read',
        'projects.deploys_show' => 'projects.read',
        'projects.environments_index' => 'projects.read',
        'projects.variables_index' => 'projects.read',
        'projects.runbooks_index' => 'projects.read',
        'projects.store' => 'projects.write',
        'projects.update' => 'projects.write',
        'projects.members_store' => 'projects.write',
        'projects.members_destroy' => 'projects.write',
        'projects.servers_attach' => 'projects.write',
        'projects.servers_detach' => 'projects.write',
        'projects.sites_attach' => 'projects.write',
        'projects.sites_detach' => 'projects.write',
        'projects.environments_store' => 'projects.write',
        'projects.environments_destroy' => 'projects.write',
        'projects.variables_upsert' => 'projects.write',
        'projects.variables_destroy' => 'projects.write',
        'projects.runbooks_store' => 'projects.write',
        'projects.runbooks_destroy' => 'projects.write',
        'projects.destroy' => 'projects.delete',
        'projects.deploy' => 'projects.deploy',

        'edge.sites.index' => 'edge.read',
        'edge.sites.show' => 'edge.read',
        'edge.deployments.index' => 'edge.read',
        'edge.deployments.show' => 'edge.read',
        'edge.deployments.store' => 'edge.deploy',
        'edge.deployments.rollback' => 'edge.deploy',
        'edge.previews.index' => 'edge.read',
        'edge.previews.store' => 'edge.deploy',
        'edge.previews.destroy' => 'edge.deploy',
        'edge.previews.promote' => 'edge.deploy',
        'edge.domains.index' => 'edge.read',
        'edge.domains.store' => 'edge.write',
        'edge.domains.verify' => 'edge.write',
        'edge.domains.destroy' => 'edge.write',
        'edge.aliases.index' => 'edge.read',
        'edge.access.show' => 'edge.read',
        'edge.access.update' => 'edge.write',
        'edge.cache.purge' => 'edge.write',
        'edge.usage.show' => 'edge.read',
        'edge.logs.index' => 'edge.read',
        'edge.lint.store' => 'edge.read',
        'edge.env.index' => 'edge.env.read',
        'edge.env.update' => 'edge.env.write',
        'edge.env.upsert' => 'edge.env.write',
        'edge.env.destroy' => 'edge.env.write',
    ],

    'categories' => [
        [
            'id' => 'servers',
            'label' => 'Servers',
            'permissions' => [
                ['ability' => 'servers.read', 'label' => 'Read'],
                ['ability' => 'commands.run', 'label' => 'Run commands'],
            ],
        ],
        [
            'id' => 'database',
            'label' => 'Database',
            'permissions' => [
                ['ability' => 'database.read', 'label' => 'Read'],
                ['ability' => 'database.write', 'label' => 'Write'],
                ['ability' => 'database.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'daemons',
            'label' => 'Daemons',
            'permissions' => [
                ['ability' => 'daemons.read', 'label' => 'Read'],
                ['ability' => 'daemons.write', 'label' => 'Write'],
                ['ability' => 'daemons.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'cronjobs',
            'label' => 'Cronjobs',
            'permissions' => [
                ['ability' => 'cronjobs.read', 'label' => 'Read'],
                ['ability' => 'cronjobs.write', 'label' => 'Write'],
                ['ability' => 'cronjobs.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'network',
            'label' => 'Network',
            'permissions' => [
                ['ability' => 'network.read', 'label' => 'Read'],
                ['ability' => 'network.write', 'label' => 'Write'],
                ['ability' => 'network.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'billing',
            'label' => 'Billing',
            'permissions' => [
                ['ability' => 'billing.read', 'label' => 'View plan, estimates, and invoices'],
            ],
        ],
        [
            'id' => 'account',
            'label' => 'Account & CLI',
            'permissions' => [
                ['ability' => 'account.read', 'label' => 'Read profile, orgs, and CLI sessions'],
                ['ability' => 'account.write', 'label' => 'Revoke CLI sessions'],
            ],
        ],
        [
            'id' => 'system_users',
            'label' => 'System users',
            'permissions' => [
                ['ability' => 'system_users.read', 'label' => 'Read'],
                ['ability' => 'system_users.write', 'label' => 'Write'],
                ['ability' => 'system_users.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'ssh_keys',
            'label' => 'SSH keys',
            'permissions' => [
                ['ability' => 'ssh_keys.read', 'label' => 'Read'],
                ['ability' => 'ssh_keys.write', 'label' => 'Write'],
                ['ability' => 'ssh_keys.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'sites',
            'label' => 'Sites',
            'permissions' => [
                ['ability' => 'sites.read', 'label' => 'Read'],
                ['ability' => 'sites.write', 'label' => 'Write (rename, domains, workers)'],
                ['ability' => 'sites.deploy', 'label' => 'Deploy'],
                ['ability' => 'sites.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'redirects',
            'label' => 'Redirects',
            'permissions' => [
                ['ability' => 'redirects.read', 'label' => 'Read'],
                ['ability' => 'redirects.write', 'label' => 'Write'],
                ['ability' => 'redirects.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'certificates',
            'label' => 'Certificates',
            'permissions' => [
                ['ability' => 'certificates.read', 'label' => 'Read'],
                ['ability' => 'certificates.write', 'label' => 'Write'],
                ['ability' => 'certificates.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'auth_users',
            'label' => 'Auth users',
            'permissions' => [
                ['ability' => 'auth_users.read', 'label' => 'Read'],
                ['ability' => 'auth_users.write', 'label' => 'Write'],
                ['ability' => 'auth_users.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'aliases',
            'label' => 'Aliases',
            'permissions' => [
                ['ability' => 'aliases.read', 'label' => 'Read'],
                ['ability' => 'aliases.write', 'label' => 'Write'],
                ['ability' => 'aliases.delete', 'label' => 'Delete'],
            ],
        ],
        [
            'id' => 'email',
            'label' => 'Email',
            'permissions' => [
                ['ability' => 'email.send', 'label' => 'Send'],
            ],
        ],
        [
            'id' => 'insights',
            'label' => 'Insights',
            'permissions' => [
                ['ability' => 'insights.read', 'label' => 'Read'],
            ],
        ],
        [
            'id' => 'imports',
            'label' => 'Imports & migrations',
            'permissions' => [
                ['ability' => 'imports.read', 'label' => 'Read'],
            ],
        ],
        [
            'id' => 'edge',
            'label' => 'Edge',
            'permissions' => [
                ['ability' => 'edge.read', 'label' => 'Read'],
                ['ability' => 'edge.deploy', 'label' => 'Deploy / rollback / promote'],
                ['ability' => 'edge.write', 'label' => 'Manage domains and cache'],
            ],
        ],
        [
            'id' => 'projects',
            'label' => 'Projects',
            'permissions' => [
                ['ability' => 'projects.read', 'label' => 'Read projects, health, and deploy history'],
                ['ability' => 'projects.write', 'label' => 'Create and update projects, members, and resources'],
                ['ability' => 'projects.deploy', 'label' => 'Queue project-wide deploys'],
                ['ability' => 'projects.delete', 'label' => 'Delete projects'],
            ],
        ],
        [
            'id' => 'edge_env',
            'label' => 'Edge env vars',
            'permissions' => [
                ['ability' => 'edge.env.read', 'label' => 'Read (keys only)'],
                ['ability' => 'edge.env.write', 'label' => 'Write'],
            ],
        ],
    ],
];
