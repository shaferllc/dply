<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Models\EdgeDeployment;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class EdgePreviewLiveDeploymentCheck implements DeployContractCheck
{
    public function key(): string
    {
        return 'edge.preview.build';
    }

    public function label(): string
    {
        return (string) __('Edge preview build');
    }

    public function engine(): string
    {
        return 'edge';
    }

    public function evaluate(DeployContractContext $context): DeployContractCheckResult
    {
        if (! $context->policy->shouldRunCheck($this->key())) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Not required by repo deploy contract.'),
            );
        }

        $deployment = $context->previewDeployment;

        if ($deployment === null
            || $deployment->status !== EdgeDeployment::STATUS_LIVE
            || $deployment->storage_prefix === null) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Preview has no live deployment with published artifacts.'),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_PASS,
            (string) __('Live preview deployment is ready to promote.'),
        );
    }
}
