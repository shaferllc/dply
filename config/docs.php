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
    ],
];
