<?php

use App\Models\ApiToken;

/**
 * Single source of truth for API token ability strings.
 *
 * - UI categories + labels: categories
 * - Organization settings “simple scope” presets: presets
 * - Deployer role API/runtime cap (must be subset of catalog): deployer_api_allowlist
 * - HTTP API v1 route middleware: http_route_abilities (values must exist in catalog or *)
 *
 * @see ApiToken::tokenAllowsAbility()
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Presets (organization → API tokens simple scopes)
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'read' => ['servers.read', 'sites.read', 'insights.read'],
        'deploy' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy'],
        'ops' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy', 'commands.run'],
        'full' => ['*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployer role: abilities allowed at runtime (API + tokenAllowsAbility)
    |--------------------------------------------------------------------------
    */
    'deployer_api_allowlist' => [
        'servers.read',
        'sites.read',
        'servers.deploy',
        'sites.deploy',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API v1 — ability checked per route (keys are informational)
    |--------------------------------------------------------------------------
    */
    'http_route_abilities' => [
        'servers.index' => 'servers.read',
        'servers.deploy' => 'servers.deploy',
        'servers.run_command' => 'commands.run',
        'sites.index' => 'sites.read',
        'sites.deploy' => 'sites.deploy',
        'sites.deployments' => 'sites.read',
        'sites.deployment_show' => 'sites.read',
        'firewall.show' => 'network.read',
        'firewall.preview' => 'network.read',
        'firewall.drift' => 'network.read',
        'firewall.terraform' => 'network.read',
        'firewall.iptables' => 'network.read',
        'firewall.export' => 'network.read',
        'firewall.apply' => 'network.write',
        'firewall.import' => 'network.write',
        'firewall.bundled_apply' => 'network.write',
        'firewall.template_apply' => 'network.write',
        'firewall.snapshot_create' => 'network.write',
        'firewall.snapshot_restore' => 'network.write',
        'insights.server_findings' => 'insights.read',
        'insights.org_summary' => 'insights.read',
    ],

    'categories' => [
        [
            'id' => 'servers',
            'label' => 'Servers',
            'permissions' => [
                ['ability' => 'servers.read', 'label' => 'Read'],
                ['ability' => 'servers.deploy', 'label' => 'Write'],
                ['ability' => 'commands.run', 'label' => 'Delete'],
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
                ['ability' => 'sites.deploy', 'label' => 'Write'],
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
    ],
];
