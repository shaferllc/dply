<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves whether dply should create a preview deploy for a given
 * (event, branch) pair on an Edge site. Reads `previews:` from the
 * site's most recent live deployment's `repo_config`. Falls back to
 * permissive defaults (PR-driven previews, no branch restrictions)
 * when nothing is declared.
 *
 * Used by the GitHub webhook handler before queueing a preview.
 */
final class EdgePreviewPolicy
{
    public const EVENT_PULL_REQUEST = 'pull_request';

    public const EVENT_PUSH = 'push';

    /**
     * @param  self::EVENT_*  $event
     */
    public static function shouldCreatePreview(Site $site, string $event, string $branch): bool
    {
        $config = self::for($site);

        if (! $config['enabled']) {
            return false;
        }

        $excludeList = $config['exclude_branches'];
        if (in_array($branch, $excludeList, true)) {
            return false;
        }

        // PR events always create previews (when enabled), regardless
        // of branch — the PR identity is the unit, not the branch.
        if ($event === self::EVENT_PULL_REQUEST) {
            return true;
        }

        // Push events: gated by pr_only flag + the explicit branch
        // whitelist. Default is pr_only=true (no push-driven previews).
        if ($config['pr_only']) {
            return false;
        }
        $branches = $config['branches'];
        if ($branches === []) {
            return true; // pr_only=false + empty whitelist → preview every push (rare)
        }

        return in_array($branch, $branches, true);
    }

    /**
     * @return array{enabled: bool, pr_only: bool, branches: list<string>, exclude_branches: list<string>, sources: array{repo: bool}}
     */
    public static function for(Site $site): array
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repoConfig = is_array($latest?->repo_config) ? $latest->repo_config : [];
        $repo = is_array($repoConfig['previews'] ?? null) ? $repoConfig['previews'] : [];

        return [
            'enabled' => (bool) ($repo['enabled'] ?? true),
            'pr_only' => (bool) ($repo['pr_only'] ?? true),
            'branches' => is_array($repo['branches'] ?? null)
                ? array_values(array_filter(array_map('strval', $repo['branches'])))
                : [],
            'exclude_branches' => is_array($repo['exclude_branches'] ?? null)
                ? array_values(array_filter(array_map('strval', $repo['exclude_branches'])))
                : [],
            'sources' => ['repo' => $repo !== []],
        ];
    }
}
