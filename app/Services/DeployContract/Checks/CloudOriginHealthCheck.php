<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class CloudOriginHealthCheck implements DeployContractCheck
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function key(): string
    {
        return 'cloud.origin.health';
    }

    public function label(): string
    {
        return (string) __('Cloud origin health');
    }

    public function engine(): string
    {
        return 'cloud';
    }

    public function evaluate(DeployContractContext $context): DeployContractCheckResult
    {
        if (! $context->policy->shouldRunCheck($this->key())) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Not required by repo deploy contract.'),
            );
        }

        $cloud = $context->linkedCloudSite;
        if ($cloud === null) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('No linked dply Cloud app for this Edge site.'),
            );
        }

        $live = $cloud->containerLiveUrl();
        if ($live === null || $live === '') {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Linked Cloud app has no live URL yet.'),
            );
        }

        $url = rtrim($live, '/').'/health';

        try {
            $response = $this->http
                ->withHeaders(['User-Agent' => 'dply-deploy-contract/1.0', 'Accept' => '*/*'])
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->get($url);
        } catch (Throwable $e) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Cloud origin unreachable: :message', ['message' => $e->getMessage()]),
            );
        }

        $status = (int) $response->status();
        if ($status >= 200 && $status < 400) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_PASS,
                (string) __('Cloud origin responded HTTP :status.', ['status' => $status]),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_FAIL,
            (string) __('Cloud origin returned HTTP :status.', ['status' => $status]),
        );
    }
}
