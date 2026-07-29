<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Support;

use Illuminate\Http\Client\Response;

/**
 * Turns a failed GitHub "create repository webhook" API response into a
 * precise, actionable message.
 *
 * GitHub returns the scopes a *classic* personal access token actually carries
 * in the `X-OAuth-Scopes` response header on every call. When a webhook create
 * is rejected we read that header and name exactly which scope is missing —
 * rather than the generic "you need admin:repo_hook" guess that can't tell a
 * scope gap apart from a repo-access or repo-admin problem.
 *
 * Decision tree:
 *   - 404 → the token can't see the repo (wrong account / no access / typo);
 *     GitHub hides private repos behind 404 rather than confirming they exist.
 *   - Classic PAT (X-OAuth-Scopes present) missing every hook-capable scope →
 *     "missing admin:repo_hook; it currently has <granted>".
 *   - Classic PAT that HAS a hook scope but is still refused → not a scope gap;
 *     the user lacks admin rights on the repository itself.
 *   - Fine-grained PAT (no scope header) → needs the Webhooks read/write repo
 *     permission.
 *   - OAuth account → re-link with the right permissions.
 */
final class GitHubWebhookFailure
{
    /** Any one of these classic-token scopes authorizes managing repo webhooks. */
    private const HOOK_SCOPES = ['admin:repo_hook', 'write:repo_hook', 'repo'];

    public static function message(Response $response, bool $isPat): string
    {
        $status = $response->status();
        $apiMessage = trim((string) ($response->json('message') ?? ''));
        $detail = $apiMessage !== '' ? ' ('.$apiMessage.')' : '';

        // 404 means the token can't see the repository at all — distinct from a
        // hook-scope problem, so don't send the user chasing scopes they may
        // already have.
        if ($status === 404) {
            return __('GitHub returned 404 for the repository — this token can\'t see it. Check the repository path and confirm this account/token has access to it.:detail', ['detail' => $detail]);
        }

        if ($isPat) {
            $grantedHeader = trim((string) $response->header('X-OAuth-Scopes'));

            // Classic token: the granted scopes are right there in the header,
            // so we can say precisely what's missing instead of guessing.
            if ($grantedHeader !== '') {
                $granted = array_values(array_filter(array_map('trim', explode(',', $grantedHeader))));
                $hasHookScope = array_intersect(self::HOOK_SCOPES, $granted) !== [];

                if (! $hasHookScope) {
                    return __('Your GitHub token is missing the admin:repo_hook scope (it currently has: :has). Regenerate the classic token with admin:repo_hook checked, then reconnect it.', [
                        'has' => $granted === [] ? __('no scopes') : implode(', ', $granted),
                    ]);
                }

                // Has a hook scope but GitHub still refused → repo-level admin
                // permission, not a token scope.
                return __('Your GitHub token has webhook scope, but GitHub refused (:status) — you need admin rights on this repository to manage its webhooks.:detail', [
                    'status' => $status,
                    'detail' => $detail,
                ]);
            }

            // No X-OAuth-Scopes header → a fine-grained token. There is no Dply
            // scope to flip here — the Webhooks permission lives entirely on the
            // token — so point the user at the exact GitHub setting and at the
            // OAuth path, which carries webhook access automatically.
            return __('GitHub rejected the webhook (:status). Give this fine-grained token the Webhooks repository permission set to Read and write, then reconnect it — or connect GitHub via OAuth instead, which grants webhook access automatically.:detail', [
                'status' => $status,
                'detail' => $detail,
            ]);
        }

        // OAuth-linked account.
        return __('GitHub rejected the webhook (:status). Re-link GitHub with repo and admin:repo_hook permissions, or check repository access.:detail', [
            'status' => $status,
            'detail' => $detail,
        ]);
    }
}
