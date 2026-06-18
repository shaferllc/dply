<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Mirror Edge preview deploys onto GitHub as Check Runs so PR pages
 * show a green check / red X + a "Details" link to the preview URL.
 *
 * Lifecycle:
 *   create()    — called when a preview site is spawned; posts a
 *                 status='in_progress' Check Run and stashes the id
 *                 in the preview site's meta.edge.github_check_run_id.
 *   complete()  — called when the publish job finishes; updates the
 *                 Check Run with status='completed' + conclusion.
 *
 * No-op (logs + returns) when the preview site has no parent webhook
 * account or no head SHA — these are best-effort enrichments, not
 * deploy-blocking.
 */
class EdgeGithubCheckRunService
{
    private const CHECK_NAME = 'dply edge preview';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly GitIdentityResolver $resolver,
    ) {}

    /**
     * Create an in-progress Check Run on the preview's source commit.
     * Returns the GitHub check_run_id on success, null on any failure
     * (we log the failure but never throw — preview deploys must not
     * fail because GitHub is down).
     */
    public function create(Site $preview): ?int
    {
        $context = $this->resolveContext($preview);
        if ($context === null) {
            return null;
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
                    'title' => 'Preview deploying',
                    'summary' => 'Building the dply Edge preview for this commit. The Details link will activate once the deploy lands.',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('EdgeGithubCheckRunService: create failed', [
                'site_id' => (string) $preview->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $id = $response->json('id');
        if (is_int($id)) {
            // ok
        } elseif (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        } else {
            return null;
        }

        $preview->mergeEdgeMeta(['github_check_run_id' => $id]);
        $preview->save();

        return $id;
    }

    /**
     * Update the Check Run to completed. $conclusion is one of GitHub's
     * documented values — typically 'success' on a LIVE deploy, 'failure'
     * on a publish error.
     */
    public function complete(Site $preview, string $conclusion, ?string $detailsUrl = null): void
    {
        $context = $this->resolveContext($preview);
        if ($context === null) {
            return;
        }

        $checkRunId = $preview->edgeMeta()['github_check_run_id'] ?? null;
        if (! is_int($checkRunId) && ! is_string($checkRunId)) {
            return;
        }

        $url = $context['account']->apiBaseUrl()
            .'/repos/'.$context['owner'].'/'.$context['repo']
            .'/check-runs/'.(int) $checkRunId;

        $body = [
            'status' => 'completed',
            'conclusion' => in_array($conclusion, ['success', 'failure', 'neutral', 'cancelled', 'timed_out'], true)
                ? $conclusion
                : 'neutral',
            'completed_at' => now()->toIso8601String(),
            'output' => [
                'title' => $conclusion === 'success' ? 'Preview live' : 'Preview failed',
                'summary' => $conclusion === 'success'
                    ? 'The dply Edge preview is live. Visit the URL via the Details link.'
                    : 'The dply Edge preview failed to publish. Check the deploy log in the dply dashboard.',
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
            Log::warning('EdgeGithubCheckRunService: complete failed', [
                'site_id' => (string) $preview->id,
                'check_run_id' => $checkRunId,
                'status' => $response->status(),
                'body' => $response->body(),
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
