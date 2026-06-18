<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Modules\Edge\Services\Config\EdgeRepoConfigLinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EdgeLintApiController extends EdgeApiController
{
    /**
     * Validate dply.yaml / dply.json content without triggering a deploy.
     * Used by `dply edge lint` and CI pipelines.
     */
    public function store(Request $request): JsonResponse
    {
        $this->organization($request);

        try {
            $data = $request->validate([
                'path' => ['required', 'string', 'max:255'],
                'content' => ['required', 'string', 'max:65536'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $result = app(EdgeRepoConfigLinter::class)->lintContent(
            (string) $data['path'],
            (string) $data['content'],
        );

        return response()->json(
            ['data' => $result],
            $result['ok'] ? 200 : 422,
        );
    }
}
