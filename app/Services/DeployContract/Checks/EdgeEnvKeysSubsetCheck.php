<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Models\EdgeSiteEnvVar;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class EdgeEnvKeysSubsetCheck implements DeployContractCheck
{
    public function key(): string
    {
        return 'edge.env.keys_subset';
    }

    public function label(): string
    {
        return (string) __('Edge env keys');
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

        $vars = EdgeSiteEnvVar::query()
            ->where('site_id', $context->parent->id)
            ->get(['key', 'scope']);

        if ($vars->isEmpty()) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('No Edge environment variables configured.'),
            );
        }

        $prodKeys = $vars->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->pluck('key')->unique()->values()->all();
        $previewKeys = $vars->where('scope', EdgeSiteEnvVar::SCOPE_PREVIEW)->pluck('key')->unique()->values()->all();

        if ($prodKeys === [] || $previewKeys === []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Both production and preview env scopes need keys to compare.'),
            );
        }

        $unknownInPreview = array_values(array_diff($previewKeys, $prodKeys));
        if ($unknownInPreview !== []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Preview env has keys missing from production: :keys', [
                    'keys' => implode(', ', array_slice($unknownInPreview, 0, 8)),
                ]),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_PASS,
            (string) __('Preview env keys are a subset of production.'),
        );
    }
}
