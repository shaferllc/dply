<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Full-stack app-object gate: don't promote an Edge frontend that references a
 * dply backend (`bindings.dply` `site.<name>`) when that backend's latest
 * deploy is failing/absent OR it isn't actually serving. Keeps "one app, one
 * promote" from shipping a frontend pointed at a broken API tier.
 *
 * Two signals per backend:
 *   1. deploy row — latest SiteDeployment must not be FAILED / never-deployed.
 *   2. live probe — when the backend exposes a URL, GET <url>/health must be
 *      2xx/3xx (mirrors CloudOriginHealthCheck). Best-effort: a backend with no
 *      derivable URL is judged on the deploy row alone, so we don't false-block.
 */
final class BackendHealthyCheck implements DeployContractCheck
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function key(): string
    {
        return 'backend.healthy';
    }

    public function label(): string
    {
        return (string) __('Backend healthy');
    }

    public function engine(): string
    {
        return 'backend';
    }

    public function evaluate(DeployContractContext $context): DeployContractCheckResult
    {
        if (! $context->policy->shouldRunCheck($this->key())) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Not required by repo deploy contract.'),
            );
        }

        if ($context->linkedBackendSites === []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('No dply.yaml site.<name> backend references.'),
            );
        }

        $failures = [];
        foreach ($context->linkedBackendSites as $backend) {
            $reason = $this->unhealthyReason($backend);
            if ($reason !== null) {
                $failures[] = $backend->name.' ('.$reason.')';
            }
        }

        if ($failures !== []) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Backend not healthy: :sites', ['sites' => implode(', ', $failures)]),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_PASS,
            (string) __('Referenced dply backends deployed cleanly and are serving.'),
        );
    }

    /**
     * Null when the backend is healthy; otherwise a short reason string.
     */
    private function unhealthyReason(Site $backend): ?string
    {
        $latest = $backend->latestDeployment();
        if ($latest === null) {
            return (string) __('never deployed');
        }
        if ($latest->status === SiteDeployment::STATUS_FAILED) {
            return (string) __('last deploy failed');
        }

        // Deploy row is clean — confirm it's actually serving when we can reach
        // a URL. No URL (e.g. VM not yet ready-for-traffic) → trust the row.
        $url = $this->backendUrl($backend);
        if ($url === null) {
            return null;
        }

        try {
            $status = (int) $this->http
                ->withHeaders(['User-Agent' => 'dply-deploy-contract/1.0', 'Accept' => '*/*'])
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->get(rtrim($url, '/').'/health')
                ->status();
        } catch (Throwable $e) {
            return (string) __('unreachable');
        }

        if ($status >= 200 && $status < 400) {
            return null;
        }

        return (string) __('health HTTP :status', ['status' => $status]);
    }

    private function backendUrl(Site $backend): ?string
    {
        foreach (['containerLiveUrl', 'visitUrl', 'edgeLiveUrl'] as $method) {
            $url = $backend->{$method}();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
