<?php

namespace App\Http\Responses;

use App\Models\CloudDeployment;

final class QueuedCloudDeploymentPayload
{
    /**
     * @return array{id: int, status: string, deployment_url: string}
     */
    public static function forModel(CloudDeployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'deployment_url' => route('cloud.deployments.show', $deployment, absolute: true),
        ];
    }
}
