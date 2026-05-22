<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cloud\CreateCloudPreviewSite;
use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound GitHub App / repo webhook for source-mode cloud sites.
 *
 * The operator pastes the webhook URL + the site's webhook_secret
 * into their GitHub repository's webhook settings (one-time setup).
 * GitHub then POSTs us push + pull_request events; we route them
 * to the right job:
 *
 *   pull_request opened|reopened|synchronize  → CreateCloudPreviewSite
 *   pull_request closed                       → TeardownCloudSiteJob (preview)
 *   push (to source branch)                   → RedeployCloudSiteJob (parent)
 *
 * Auth: HMAC SHA256 of the raw body, signed with the site's
 * webhook_secret, presented as `X-Hub-Signature-256: sha256=<hex>`.
 * Bad signature → 403. Wrong site type → 422. Anything else
 * (irrelevant event, push to non-source branch, missing payload)
 * → 200 with a "no-op" reason so GitHub doesn't keep retrying.
 */
class GithubCloudWebhookController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');
        if (! $this->verifySignature($request, $site, $signatureHeader)) {
            return response()->json(['ok' => false, 'reason' => 'invalid_signature'], 403);
        }

        if (! $site->usesContainerRuntime()) {
            return response()->json(['ok' => false, 'reason' => 'not_a_container_site'], 422);
        }
        if (! is_array($site->meta['container']['source'] ?? null)) {
            return response()->json(['ok' => false, 'reason' => 'site_is_not_source_mode'], 422);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'reason' => 'no_payload'], 200);
        }

        return match ($event) {
            'pull_request' => $this->handlePullRequest($site, $payload),
            'push' => $this->handlePush($site, $payload),
            'ping' => response()->json(['ok' => true, 'event' => 'ping']),
            default => response()->json(['ok' => true, 'queued' => false, 'reason' => 'event_ignored', 'event' => $event]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePullRequest(Site $site, array $payload): JsonResponse
    {
        $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $branch = is_string($pr['head']['ref'] ?? null) ? (string) $pr['head']['ref'] : '';
        $prNumber = is_int($pr['number'] ?? null) ? (int) $pr['number'] : null;

        if ($branch === '') {
            return response()->json(['ok' => false, 'reason' => 'no_branch'], 200);
        }

        if (in_array($action, ['opened', 'reopened', 'synchronize'], true)) {
            $preview = (new CreateCloudPreviewSite)->handle($site, $branch, $prNumber);

            return response()->json([
                'ok' => true,
                'queued' => 'preview',
                'preview_id' => $preview->id,
                'branch' => $branch,
            ]);
        }

        if ($action === 'closed') {
            $preview = CreateCloudPreviewSite::findExisting($site, $branch);
            if ($preview === null) {
                return response()->json(['ok' => true, 'queued' => false, 'reason' => 'no_preview']);
            }
            TeardownCloudSiteJob::dispatch($preview->id);

            return response()->json([
                'ok' => true,
                'queued' => 'teardown',
                'preview_id' => $preview->id,
                'branch' => $branch,
            ]);
        }

        return response()->json(['ok' => true, 'queued' => false, 'reason' => 'pr_action_ignored', 'action' => $action]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePush(Site $site, array $payload): JsonResponse
    {
        $ref = is_string($payload['ref'] ?? null) ? (string) $payload['ref'] : '';
        $branch = preg_replace('#^refs/heads/#', '', $ref) ?? '';
        $sourceBranch = (string) ($site->meta['container']['source']['branch'] ?? 'main');

        if ($branch === '' || $branch !== $sourceBranch) {
            return response()->json([
                'ok' => true,
                'queued' => false,
                'reason' => 'push_branch_does_not_match_source',
                'pushed_branch' => $branch,
                'source_branch' => $sourceBranch,
            ]);
        }

        RedeployCloudSiteJob::dispatch($site->id);

        return response()->json([
            'ok' => true,
            'queued' => 'redeploy',
            'site' => $site->id,
            'branch' => $branch,
        ]);
    }

    private function verifySignature(Request $request, Site $site, string $signatureHeader): bool
    {
        if ($signatureHeader === '' || ! is_string($site->webhook_secret) || $site->webhook_secret === '') {
            return false;
        }
        $expectedPrefix = 'sha256=';
        if (! str_starts_with($signatureHeader, $expectedPrefix)) {
            return false;
        }
        $provided = substr($signatureHeader, strlen($expectedPrefix));
        $expected = hash_hmac('sha256', $request->getContent(), $site->webhook_secret);

        return hash_equals($expected, $provided);
    }
}
