<?php

namespace App\Http\Responses;

use App\Models\EdgeDeployment;

final class QueuedEdgeDeploymentPayload
{
    /**
     * @return array{id: int, status: string, deployment_url: string}
     */
    public static function forModel(EdgeDeployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'deployment_url' => route('edge.deployments.show', $deployment, absolute: true),
        ];
    }
}
