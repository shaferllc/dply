<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Responses\QueuedServerlessDeploymentPayload;
use App\Models\ServerlessFunctionDeployment;
use App\Services\Serverless\DispatchServerlessFunctionDeployment;
use App\Services\Serverless\ServerlessWebhookSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ServerlessDeployWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ServerlessWebhookSignatureValidator $webhookValidator,
        DispatchServerlessFunctionDeployment $dispatch,
    ): JsonResponse {
        $result = $webhookValidator->validate($request);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        try {
            $deployment = $dispatch->fromRequest($request, ServerlessFunctionDeployment::TRIGGER_WEBHOOK);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'message' => $result['message'],
        ], QueuedServerlessDeploymentPayload::forModel($deployment)), 202);
    }
}
