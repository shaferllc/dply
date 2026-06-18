<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * GitHub Check Run for deploy contract results (separate from preview build check).
 */
class EdgeGithubDeployContractCheckService
{
    private const CHECK_NAME = 'dply deploy contract';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly GitIdentityResolver $resolver,
    ) {}

    public function start(Site $preview): void
    {
        $context = $this->resolveContext($preview);
        if ($context === null) {
            return;
        }

        $url = $context['account']->apiBaseUrl().'/repos/'.$context['owner'].'/'.$context['repo'].'/check-runs';

        $response = $this->http
            ->withToken($context['account']->accessToken())
            ->acceptJson()
            ->timeout(10)
            ->post($url, [
                'name' => self::CHECK_NAME,
                'head_sha' => $context['head_sha'],
                'status' => 'in_progress',
                'started_at' => now()->toIso8601String(),
                'output' => [
                    'title' => 'Deploy contract running',
                    'summary' => 'Evaluating promote checks across Edge, Cloud, and linked BYO sites.',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('EdgeGithubDeployContractCheckService: start failed', [
                'site_id' => (string) $preview->id,
                'status' => $response->status(),
            ]);

            return;
        }

        $id = $response->json('id');
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (! is_int($id)) {
            return;
        }

        $preview->mergeEdgeMeta(['github_deploy_contract_check_run_id' => $id]);
        $preview->save();
    }

    /**
     * @param  array<string, mixed> $checks
     */
    public function complete(
        Site $preview,
        string $conclusion,
        ?string $detailsUrl,
        array $checks,
    ): void {
        $context = $this->resolveContext($preview);
        if ($context === null) {
            return;
        }

        $checkRunId = $preview->edgeMeta()['github_deploy_contract_check_run_id'] ?? null;
        if (! is_int($checkRunId) && ! (is_string($checkRunId) && ctype_digit($checkRunId))) {
            return;
        }

        $url = $context['account']->apiBaseUrl()
            .'/repos/'.$context['owner'].'/'.$context['repo']
            .'/check-runs/'.(int) $checkRunId;

        $lines = [];
        foreach ($checks as $row) {
            $lines[] = sprintf('- **%s** (%s): %s', $row['label'] ?? $row['key'], $row['status'], $row['message']);
        }

        $body = [
            'status' => 'completed',
            'conclusion' => in_array($conclusion, ['success', 'failure', 'neutral', 'cancelled', 'timed_out'], true)
                ? $conclusion
                : 'neutral',
            'completed_at' => now()->toIso8601String(),
            'output' => [
                'title' => $conclusion === 'success' ? 'Deploy contract passed' : 'Deploy contract failed',
                'summary' => $conclusion === 'success'
                    ? 'All required promote checks passed. Production promote is allowed in dply.'
                    : 'One or more promote checks failed. Fix issues or record a waiver in dply.',
                'text' => implode("\n", $lines),
            ],
        ];

        if ($detailsUrl !== null && $detailsUrl !== '') {
            $body['details_url'] = $detailsUrl;
        }

        $response = $this->http
            ->withToken($context['account']->accessToken())
            ->acceptJson()
            ->timeout(10)
            ->patch($url, $body);

        if (! $response->successful()) {
            Log::warning('EdgeGithubDeployContractCheckService: complete failed', [
                'site_id' => (string) $preview->id,
                'check_run_id' => $checkRunId,
                'status' => $response->status(),
            ]);
        }
    }

    /**
     * @return array{account: object, owner: string, repo: string, head_sha: string}|null
     */
    private function resolveContext(Site $preview): ?array
    {
        $edge = $preview->edgeMeta();
        $headSha = trim((string) ($edge['preview_head_sha'] ?? ''));
        if ($headSha === '') {
            return null;
        }

        $parentId = $edge['preview_parent_site_id'] ?? null;
        $parentId = is_scalar($parentId) ? trim((string) $parentId) : '';
        if ($parentId === '') {
            return null;
        }
        $parent = Site::query()->find($parentId);
        if ($parent === null || $parent->user === null) {
            return null;
        }

        $repo = trim((string) ($parent->edgeMeta()['source']['repo'] ?? ''));
        if (! str_contains($repo, '/')) {
            return null;
        }
        [$owner, $name] = explode('/', $repo, 2);

        $webhook = is_array($parent->edgeMeta()['webhook'] ?? null) ? $parent->edgeMeta()['webhook'] : null;
        $accountId = is_array($webhook) ? (string) ($webhook['account_id'] ?? '') : '';
        if ($accountId === '') {
            return null;
        }
        $account = $this->resolver->forId($parent->user, $accountId);
        if ($account === null) {
            return null;
        }

        return [
            'account' => $account,
            'owner' => $owner,
            'repo' => $name,
            'head_sha' => $headSha,
        ];
    }
}
