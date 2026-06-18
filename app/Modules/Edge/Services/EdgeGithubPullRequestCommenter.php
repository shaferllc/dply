<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Maintain a single dply-owned PR comment summarizing the preview
 * deploy state (queued → live, with the live URL).
 *
 * Idempotent — first call POSTs a new comment and stashes the id on
 * the preview's meta.edge.github_comment_id; subsequent calls PATCH
 * the same comment so the PR isn't spammed with one comment per
 * status change.
 *
 * Best-effort throughout — failures are logged but never thrown so a
 * GitHub outage cannot block a preview deploy.
 */
class EdgeGithubPullRequestCommenter
{
    private const MARKER = '<!-- dply-edge-preview -->';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly GitIdentityResolver $resolver,
    ) {}

    /**
     * Upsert the preview comment. $stage is one of:
     *   'building'  — initial post, preview build started
     *   'success'   — preview is live; pass $liveUrl
     *   'failure'   — preview deploy failed
     */
    public function upsert(Site $preview, string $stage, ?string $liveUrl = null): void
    {
        $context = $this->resolveContext($preview);
        if ($context === null) {
            return;
        }

        $body = $this->renderBody($stage, $liveUrl, $context['head_sha']);
        $existing = $preview->edgeMeta()['github_comment_id'] ?? null;

        if (is_int($existing) || (is_string($existing) && $existing !== '')) {
            $this->patch($context, (int) $existing, $body);

            return;
        }

        $newId = $this->post($context, $body);
        if ($newId !== null) {
            $preview->mergeEdgeMeta(['github_comment_id' => $newId]);
            $preview->save();
        }
    }

    private function renderBody(string $stage, ?string $liveUrl, string $headSha): string
    {
        $short = substr($headSha, 0, 7);
        $statusLine = match ($stage) {
            'success' => '✅ **Preview live** for `'.$short.'`'.($liveUrl ? ' — '.$liveUrl : ''),
            'failure' => '❌ **Preview failed** for `'.$short.'` — check the deploy log in the dply dashboard',
            default => '⏳ **Preview building** for `'.$short.'`',
        };

        return self::MARKER."\n## dply Edge preview\n\n".$statusLine."\n\n_This comment is updated automatically by dply on each push._";
    }

    /**
     * @param  array{account: object, owner: string, repo: string, head_sha: string, pr_number: int}  $context
     */
    private function post(array $context, string $body): ?int
    {
        $url = $context['account']->apiBaseUrl()
            .'/repos/'.$context['owner'].'/'.$context['repo']
            .'/issues/'.$context['pr_number'].'/comments';

        $response = $this->http
            ->withToken($context['account']->accessToken())
            ->acceptJson()
            ->timeout(10)
            ->post($url, ['body' => $body]);

        if (! $response->successful()) {
            Log::warning('EdgeGithubPullRequestCommenter: POST failed', [
                'pr' => $context['pr_number'],
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $id = $response->json('id');
        if (is_int($id)) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * @param  array{account: object, owner: string, repo: string, head_sha: string, pr_number: int}  $context
     */
    private function patch(array $context, int $commentId, string $body): void
    {
        $url = $context['account']->apiBaseUrl()
            .'/repos/'.$context['owner'].'/'.$context['repo']
            .'/issues/comments/'.$commentId;

        $response = $this->http
            ->withToken($context['account']->accessToken())
            ->acceptJson()
            ->timeout(10)
            ->patch($url, ['body' => $body]);

        if (! $response->successful()) {
            Log::warning('EdgeGithubPullRequestCommenter: PATCH failed', [
                'pr' => $context['pr_number'],
                'comment_id' => $commentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * @return array{account: object, owner: string, repo: string, head_sha: string, pr_number: int}|null
     */
    private function resolveContext(Site $preview): ?array
    {
        $edge = $preview->edgeMeta();
        $prNumber = $edge['preview_pr_number'] ?? null;
        $headSha = trim((string) ($edge['preview_head_sha'] ?? ''));
        if (! is_int($prNumber) || $prNumber <= 0 || $headSha === '') {
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
            'pr_number' => $prNumber,
        ];
    }
}
