<?php

/**
 * Contextual documentation routing for the in-app docs sidebar.
 *
 * Routes are matched top-to-bottom. The first matching entry wins.
 * Use `group` to pick the product-scoped guide list shown in the sidebar.
 */

return [
    'routes' => [
        [
            // Activity merged into the Logs page; surface its audit-log doc when
            // the Activity tab is active. Without the tab param, the Logs page
            // falls through to its own `server-logs` doc via the nav-key map.
            'route' => 'servers.logs',
            'params' => ['tab' => 'activity'],
            'slug' => 'server-activity',
            'group' => 'servers',
        ],
        [
            'route' => 'edge.index',
            'slug' => 'edge-fleet',
            'group' => 'edge',
        ],
        [
            'route' => 'edge.create',
            'slug' => 'edge-create',
            'group' => 'edge',
        ],
        [
            'route' => 'sites.preview-comments',
            'slug' => 'edge-preview-comments',
            'group' => 'edge',
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-create',
            'group' => 'edge',
            'when' => 'edge_site_provisioning',
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-site-overview',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'general',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-deploys',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-deploys',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-domains',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-routing',
                'tab' => 'domains',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-build',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-build',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-environment',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-environment',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-deploy-triggers',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-deploy-triggers',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-delivery',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-delivery',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-routing',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-routing',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-error-pages',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-error-pages',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-firewall',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-firewall',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-bot-protection',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-bot-protection',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-rate-limits',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-rate-limits',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-waiting-room',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-waiting-room',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-forms',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-forms',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-jobs',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-jobs',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-crons',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-crons',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-snippets',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-snippets',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-tags',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-tags',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-bindings',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-bindings',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-members',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-members',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-alerts',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-alerts',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-audit',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-audit',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-previews',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-previews',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-traffic',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-traffic',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-billing',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-billing',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-logs',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'edge-logs',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-danger',
            'group' => 'edge',
            'when' => 'edge_site',
            'params' => [
                'section' => 'danger',
            ],
        ],
        [
            'route' => 'sites.show',
            'slug' => 'edge-site-overview',
            'group' => 'edge',
            'when' => 'edge_site',
        ],
        [
            'route' => 'organizations.index',
            'slug' => 'org-roles-and-limits',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.show',
            'slug' => 'org-overview',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.settings',
            'slug' => 'org-general',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.activity',
            'slug' => 'org-activity',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.automation',
            'slug' => 'org-automation',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.members',
            'slug' => 'org-members',
            'group' => 'organization',
        ],
        [
            'route' => 'organizations.teams',
            'slug' => 'org-teams',
            'group' => 'organization',
        ],
        [
            'route' => 'billing.show',
            'slug' => 'billing-and-plans',
            'group' => 'organization',
        ],
        [
            'route' => 'billing.analytics',
            'slug' => 'billing-and-plans',
            'group' => 'organization',
        ],
        [
            'route' => 'billing.invoices',
            'slug' => 'billing-and-plans',
            'group' => 'organization',
        ],
        [
            'route' => 'profile.source-control',
            'slug' => 'source-control',
            'group' => 'organization',
        ],
        [
            'route' => 'profile.api-keys',
            'slug' => 'api',
            'group' => 'organization',
        ],
        [
            'route' => 'profile.ssh-keys',
            'slug' => 'account-ssh-keys',
            'group' => 'account',
        ],
        [
            'route' => 'profile.security',
            'slug' => 'account-security',
            'group' => 'account',
        ],
        [
            'route' => 'profile.backup-configurations',
            'slug' => 'account-backup-destinations',
            'group' => 'account',
        ],
        [
            'route' => 'profile.cli',
            'slug' => 'account-cli',
            'group' => 'account',
        ],
        [
            'route' => 'projects.index',
            'slug' => 'projects-overview',
            'group' => 'organization',
        ],
        [
            'route' => 'projects.show',
            'slug' => 'projects-overview',
            'group' => 'organization',
        ],
        [
            'route' => 'credentials.index',
            'slug' => 'connect-provider',
            'group' => 'sites',
        ],
        [
            'route' => 'servers.create',
            'slug' => 'create-first-server',
            'group' => 'sites',
        ],
        [
            'route' => 'servers.provision',
            'slug' => 'create-first-server',
            'group' => 'sites',
        ],
        [
            'route' => 'sites.create',
            'slug' => 'sites-and-deploy',
            'group' => 'sites',
        ],
        [
            'route' => 'docs.index',
            'slug' => null,
            'group' => null,
            'mode' => 'index',
        ],
    ],

    ...(require __DIR__.'/contextual-docs-maps.php'),

    'fallbacks' => [
        'edge' => 'edge-overview',
        'sites' => 'vm-site-overview',
        'servers' => 'server-overview',
        'organization' => 'org-roles-and-limits',
        'default' => 'edge-overview',
    ],
];
