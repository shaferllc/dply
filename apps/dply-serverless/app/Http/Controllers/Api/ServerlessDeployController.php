<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\QueuedServerlessDeploymentPayload;
use App\Models\ServerlessFunctionDeployment;
use App\Services\Serverless\DispatchServerlessFunctionDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ServerlessDeployController extends Controller
{
    public function __invoke(Request $request, DispatchServerlessFunctionDeployment $dispatch): JsonResponse
    {
        try {
            $deployment = $dispatch->fromRequest($request, ServerlessFunctionDeployment::TRIGGER_API);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'message' => 'Deployment queued.',
        ], QueuedServerlessDeploymentPayload::forModel($deployment)), 202);
    }
}
