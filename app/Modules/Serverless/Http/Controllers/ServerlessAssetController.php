<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use Illuminate\Http\Response;

/**
 * Public origin for Functions Laravel Vite/build assets published off the
 * function. Hashed filenames + long cache; path traversal is rejected.
 */
class ServerlessAssetController
{
    public function __invoke(
        Site $site,
        ServerlessAssetPublisher $publisher,
        string $path = '',
    ): Response {
        if (! $site->usesFunctionsRuntime()) {
            abort(404);
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            abort(404);
        }

        $contents = $publisher->read($site, $relative);
        if ($contents === null) {
            abort(404);
        }

        $mime = $publisher->mimeFor($relative);
        $cache = preg_match('/\.[a-f0-9]{8,}\./', $relative) === 1
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600';

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
