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
                'section' => 'edge-domains',
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

    'fallbacks' => [
        'edge' => 'edge-overview',
        'sites' => 'sites-and-deploy',
        'organization' => 'org-roles-and-limits',
        'default' => 'edge-overview',
    ],
];
