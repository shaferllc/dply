<?php

namespace App\Http\Responses;

use App\Models\WordpressDeployment;

final class QueuedWordpressDeploymentPayload
{
    /**
     * @return array{id: int, status: string, deployment_url: string}
     */
    public static function forModel(WordpressDeployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'deployment_url' => route('wordpress.deployments.show', $deployment, absolute: true),
        ];
    }
}
