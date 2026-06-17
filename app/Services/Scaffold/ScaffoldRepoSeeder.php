<?php

declare(strict_types=1);

namespace App\Services\Scaffold;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Seeds a Git repository for a freshly-scaffolded *code-first* app (Laravel,
 * Symfony, Statamic, Craft) so it becomes versioned, redeployable, rollback-able
 * and resettable — and so the standalone Repository page has something real to
 * show. WordPress / Drupal are "manage-in-place" and intentionally skipped (no
 * developer repo; the install itself is the source of truth).
 *
 * Tier 1 (this class): a **local bare repo** on the box as origin. Every code-first
 * scaffold gets a uniform pipeline with zero external dependency. The off-box
 * provider remote (GitHub/GitLab/Bitbucket) is a later layer that pushes this
 * history up and re-points origin.
 *
 * The initial commit is **source-only** — it honours the framework's shipped
 * .gitignore and force-excludes `.env`, so APP_KEY / DB credentials are never
 * committed (they live in the managed env layer and are injected at deploy).
 *
 * Callers run this best-effort: a failure must never fail the scaffold — the
 * site stays live in its manage-in-place state and a repo can be connected later.
 */
class ScaffoldRepoSeeder
{
    /** Frameworks that get a seeded repo. WordPress/Drupal are manage-in-place. */
    private const CODE_FIRST = ['laravel', 'symfony', 'statamic', 'craft'];

    public function __construct(private readonly ExecuteRemoteTaskOnServer $executor) {}

    /**
     * @return bool true when a repo was seeded, false when skipped (manage-in-place)
     *
     * @throws \RuntimeException on a remote failure (caller catches — best-effort)
     */
    public function seed(Site $site): bool
    {
        $framework = strtolower((string) ($site->meta['scaffold']['framework'] ?? ''));
        if (! in_array($framework, self::CODE_FIRST, true)) {
            return false;
        }

        $workTree = '/home/dply/'.$site->slug.'/current';
        $bare = '/home/dply/'.$site->slug.'.git';

        // Runs as the dply deploy user under `set -euo pipefail`, so anything
        // that may legitimately exit non-zero (no staged changes, no existing
        // remote) is guarded with `||`.
        $script = sprintf(
            <<<'BASH'
            cd %1$s
            touch .gitignore
            grep -qxF '.env' .gitignore || echo '.env' >> .gitignore
            if [ ! -d .git ]; then
                git init -q
                git symbolic-ref HEAD refs/heads/main
            fi
            git config user.email 'scaffold@dply.io'
            git config user.name 'dply'
            git add -A
            git diff --cached --quiet || git commit -q -m %3$s
            rm -rf %2$s
            git init -q --bare %2$s
            git remote remove origin 2>/dev/null || true
            git remote add origin %2$s
            git push -q origin main
            BASH,
            escapeshellarg($workTree),
            escapeshellarg($bare),
            escapeshellarg('Initial '.ucfirst($framework).' scaffold via dply'),
        );

        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'scaffold:seed-repo',
            inlineBash: $script,
            timeoutSeconds: 180,
        );
        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('Repo seed failed: '.$out->getBuffer());
        }

        // Point the site at its new origin: future deploys clone/pull from the
        // bare repo, and the Repository page now resolves. The local path is the
        // origin until a provider remote replaces it (off-box copy).
        $meta = ($site->meta );
        data_set($meta, 'scaffold.repo_seeded', true);
        data_set($meta, 'scaffold.repo_origin', $bare);
        $site->forceFill([
            'git_repository_url' => $bare,
            'git_branch' => 'main',
            'meta' => $meta,
        ])->save();

        return true;
    }
}
