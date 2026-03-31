<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OperatorReadmeController extends Controller
{
    public function show(): JsonResponse
    {
        $path = base_path('README.md');
        if (! is_readable($path)) {
            return response()->json([
                'error' => 'README.md not found',
            ], 404);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return response()->json([
                'error' => 'Unable to read README.md',
            ], 500);
        }

        return response()->json([
            'format' => 'markdown',
            'title' => (string) config('app.name'),
            'content' => $content,
        ]);
    }
}
