<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\QueuedCloudDeploymentPayload;
use App\Models\CloudDeployment;
use App\Services\Cloud\DispatchCloudDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CloudDeployController extends Controller
{
    public function __invoke(Request $request, DispatchCloudDeployment $dispatch): JsonResponse
    {
        try {
            $deployment = $dispatch->fromRequest($request, CloudDeployment::TRIGGER_API);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'message' => 'Deployment queued.',
        ], QueuedCloudDeploymentPayload::forModel($deployment)), 202);
    }
}
