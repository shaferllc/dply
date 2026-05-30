<?php

use App\Http\Controllers\DocsController;

/**
 * User-facing markdown docs under /docs/{slug}.
 *
 * To add a page:
 * 1. Add a file under /docs (repo root), named as in `file`.
 * 2. Append an entry below with URL slug, filename, and browser title.
 * 3. Link it from resources/views/docs/index.blade.php (use route name `docs.markdown`).
 *
 * HTTP API uses a dedicated route /docs/api → {@see DocsController::apiDocumentation}.
 *
 * @see DocsController::markdown
 */

return [
    'markdown' => [
        'org-roles-and-limits' => [
            'file' => 'ORG_ROLES_AND_LIMITS.md',
            'title' => 'Organization roles & plan limits',
        ],
        'source-control' => [
            'file' => 'DEPLOYMENT_FLOW.md',
            'title' => 'Source control & deploy flow',
        ],
        'sites-and-deploy' => [
            'file' => 'SITES_AND_DEPLOY.md',
            'title' => 'Sites, DNS & deploy',
        ],
        'credentials' => [
            'file' => 'CREDENTIALS_OVERVIEW.md',
            'title' => 'Server providers vs Git',
        ],
        'billing-and-plans' => [
            'file' => 'BILLING_AND_PLANS.md',
            'title' => 'Billing & plans',
        ],
        'server-workspace' => [
            'file' => 'SERVER_WORKSPACE_OVERVIEW.md',
            'title' => 'Server workspace overview',
        ],
        'local-development' => [
            'file' => 'BYO_LOCAL_SETUP.md',
            'title' => 'Local development',
        ],
        'deploy-badge' => [
            'file' => 'DEPLOY_BADGE.md',
            'title' => 'Deploy to dply badge',
        ],
        'edge-overview' => [
            'file' => 'EDGE_OVERVIEW.md',
            'title' => 'Edge overview',
        ],
        'edge-fleet' => [
            'file' => 'EDGE_FLEET.md',
            'title' => 'Edge fleet index',
        ],
        'edge-create' => [
            'file' => 'EDGE_CREATE.md',
            'title' => 'Create an Edge app',
        ],
        'edge-site-overview' => [
            'file' => 'EDGE_SITE_OVERVIEW.md',
            'title' => 'Edge site overview',
        ],
        'edge-deploys' => [
            'file' => 'EDGE_DEPLOYS.md',
            'title' => 'Edge deploys',
        ],
        'edge-domains' => [
            'file' => 'EDGE_DOMAINS.md',
            'title' => 'Edge domains',
        ],
        'edge-build' => [
            'file' => 'EDGE_BUILD.md',
            'title' => 'Edge build',
        ],
        'edge-environment' => [
            'file' => 'EDGE_ENVIRONMENT.md',
            'title' => 'Edge environment variables',
        ],
        'edge-deploy-triggers' => [
            'file' => 'EDGE_DEPLOY_TRIGGERS.md',
            'title' => 'Edge deploy triggers',
        ],
        'edge-delivery' => [
            'file' => 'EDGE_DELIVERY.md',
            'title' => 'Edge delivery',
        ],
        'edge-routing' => [
            'file' => 'EDGE_ROUTING.md',
            'title' => 'Edge routing',
        ],
        'edge-previews' => [
            'file' => 'EDGE_PREVIEWS.md',
            'title' => 'Edge previews',
        ],
        'edge-traffic' => [
            'file' => 'EDGE_TRAFFIC.md',
            'title' => 'Edge traffic & analytics',
        ],
        'edge-billing' => [
            'file' => 'EDGE_BILLING.md',
            'title' => 'Edge billing & usage',
        ],
        'edge-logs' => [
            'file' => 'EDGE_LOGS.md',
            'title' => 'Edge build & deploy logs',
        ],
        'edge-danger' => [
            'file' => 'EDGE_DANGER.md',
            'title' => 'Delete an Edge site',
        ],
        'edge-preview-comments' => [
            'file' => 'EDGE_PREVIEW_COMMENTS.md',
            'title' => 'Edge preview comments',
        ],
        ...(require __DIR__.'/docs-vm-guides.php'),
    ],

    /**
     * Pages with dedicated routes or markdown aliases for the docs sidebar.
     */
    'virtual' => [
        'api' => [
            'file' => 'HTTP_API.md',
            'title' => 'HTTP API',
            'route' => 'docs.api',
        ],
        'connect-provider' => [
            'title' => 'Connect a cloud provider',
            'summary' => 'Get an API token from DigitalOcean or Hetzner and add it under Server providers.',
            'route' => 'docs.connect-provider',
        ],
        'create-first-server' => [
            'title' => 'Create your first server',
            'summary' => 'Choose provider, region, size, optional setup script; then deploy.',
            'route' => 'docs.create-first-server',
        ],
    ],

    /**
     * Product-scoped guide lists for the docs sidebar jump nav.
     *
     * @var array<string, array{label: string, slugs: list<string>}>
     */
    'groups' => [
        'edge' => [
            'label' => 'Edge guides',
            'slugs' => [
                'edge-overview',
                'edge-fleet',
                'edge-create',
                'edge-site-overview',
                'edge-deploys',
                'edge-domains',
                'edge-build',
                'edge-environment',
                'edge-deploy-triggers',
                'edge-delivery',
                'edge-routing',
                'edge-previews',
                'edge-traffic',
                'edge-billing',
                'edge-logs',
                'edge-danger',
                'edge-preview-comments',
            ],
        ],
        ...(require __DIR__.'/docs-vm-guide-sidebar-groups.php'),
        'sites' => [
            'label' => 'Getting started',
            'slugs' => [
                'create-first-server',
                'connect-provider',
                'sites-and-deploy',
                'server-workspace',
                'local-development',
            ],
        ],
        'organization' => [
            'label' => 'Organization',
            'slugs' => [
                'org-roles-and-limits',
                'billing-and-plans',
                'credentials',
                'source-control',
                'api',
            ],
        ],
    ],
];
