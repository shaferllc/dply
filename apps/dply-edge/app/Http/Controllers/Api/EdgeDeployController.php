<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\QueuedEdgeDeploymentPayload;
use App\Models\EdgeDeployment;
use App\Services\Edge\DispatchEdgeDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EdgeDeployController extends Controller
{
    public function __invoke(Request $request, DispatchEdgeDeployment $dispatch): JsonResponse
    {
        try {
            $deployment = $dispatch->fromRequest($request, EdgeDeployment::TRIGGER_API);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'message' => 'Deployment queued.',
        ], QueuedEdgeDeploymentPayload::forModel($deployment)), 202);
    }
}
