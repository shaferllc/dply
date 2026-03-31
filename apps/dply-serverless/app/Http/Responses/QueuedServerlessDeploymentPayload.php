<?php

namespace App\Http\Responses;

use App\Models\ServerlessFunctionDeployment;

final class QueuedServerlessDeploymentPayload
{
    /**
     * @return array{id: int, status: string, deployment_url: string}
     */
    public static function forModel(ServerlessFunctionDeployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'deployment_url' => route('serverless.deployments.show', $deployment, absolute: true),
        ];
    }
}
