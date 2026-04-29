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
    ],
];
