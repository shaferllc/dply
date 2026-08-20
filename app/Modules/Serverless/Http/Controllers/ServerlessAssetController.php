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

        $response = $publisher->responseFor($site, $path);
        abort_if($response === null, 404);

        return $response;
    }
}
