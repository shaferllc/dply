<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\QueuedWordpressDeploymentPayload;
use App\Models\WordpressDeployment;
use App\Services\Wordpress\DispatchWordpressDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WordpressDeployController extends Controller
{
    public function __invoke(Request $request, DispatchWordpressDeployment $dispatch): JsonResponse
    {
        try {
            $deployment = $dispatch->fromRequest($request, WordpressDeployment::TRIGGER_API);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'message' => 'Deployment queued.',
        ], QueuedWordpressDeploymentPayload::forModel($deployment)), 202);
    }
}
