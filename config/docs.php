<?php

use App\Modules\Docs\Http\Controllers\DocsController;
use App\Modules\Docs\Services\DocsManifest;

/**
 * User-facing docs are now driven by YAML front-matter in the docs/ markdown
 * files — see {@see DocsManifest}. To publish a page, add a
 * doc with front-matter (title/slug/category/order/description/group) and run
 * `php artisan docs:flush`. The legacy `markdown`/`groups` registries (and
 * docs-vm-guides*.php) were retired in favour of the manifest.
 *
 * Only route-backed "virtual" pages live here: pages with a dedicated route
 * and/or a markdown alias that the manifest folds into the published set.
 *
 * @see DocsController
 */

return [
    'virtual' => [
        'api' => [
            'file' => 'HTTP_API.md',
            'title' => 'HTTP API',
            'category' => 'Reference',
            'route' => 'docs.api',
        ],
        'connect-provider' => [
            'title' => 'Connect a cloud provider',
            'category' => 'Getting started',
            'summary' => 'Get an API token from DigitalOcean or Hetzner and add it under Server providers.',
            'route' => 'docs.connect-provider',
        ],
        'create-first-server' => [
            'title' => 'Create your first server',
            'category' => 'Getting started',
            'summary' => 'Choose provider, region, size, optional setup script; then deploy.',
            'route' => 'docs.create-first-server',
        ],
    ],
];
