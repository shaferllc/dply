<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Models\SiteDeployment;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class ByoLinkedDeployHealthCheck implements DeployContractCheck
{
    public function key(): string
    {
        return 'byo.deploy.health';
    }

    public function label(): string
    {
        return (string) __('BYO linked deploy');
    }

    public function engine(): string
    {
        return 'byo';
    }

    public function evaluate(DeployContractContext $context): DeployContractCheckResult
    {
        if (! $context->policy->shouldRunCheck($this->key())) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Not required by repo deploy contract.'),
            );
        }

        if ($context->linkedByoSites === []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('No BYO sites share this Git repository.'),
            );
        }

        $failures = [];
        foreach ($context->linkedByoSites as $byo) {
            $latest = SiteDeployment::query()
                ->where('site_id', $byo->id)
                ->latest('id')
                ->first();

            if ($latest === null) {
                continue;
            }

            if ($latest->status === SiteDeployment::STATUS_FAILED) {
                $failures[] = $byo->name;
            }
        }

        if ($failures !== []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Latest deploy failed on: :sites', ['sites' => implode(', ', $failures)]),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_PASS,
            (string) __('Linked BYO sites have no recent failed deploys.'),
        );
    }
}
