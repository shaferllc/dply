<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Update a site's git repository URL, branch, and/or path.
 *
 *   dply:site:set-repo <site> [--url=...] [--branch=...] [--path=...]
 *                              [--dry-run] [--json]
 *
 * Each option is independent; omit one to leave that field alone.
 * Common workflows:
 *
 *   --url=git@github.com:org/new-name.git    # repo got renamed
 *   --branch=main                            # default branch policy
 *   --path=apps/web                          # monorepo subdir
 *   --branch= (empty)                        # clear an override
 *
 * Validates the URL looks plausibly like git (SSH or HTTPS) so
 * typos don't sneak through. --dry-run shows the diff without
 * writing.
 *
 * NOTE: the next deploy uses the new values. This command does NOT
 * trigger a redeploy — chain with dply:site:deploy if needed.
 */
class SetSiteRepoCommand extends Command
{
    protected $signature = 'dply:site:set-repo
        {site : Site ID, slug, or name}
        {--url= : Git repository URL (SSH or HTTPS)}
        {--branch= : Git branch to deploy from}
        {--path= : Repository subpath (for monorepos)}
        {--dry-run : Report the proposed change without writing}
        {--json : Output as JSON}';

    protected $description = 'Update a site\'s git repository URL, branch, and/or subpath.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $changes = [];

        $url = $this->option('url');
        if ($url !== null) {
            $url = trim((string) $url);
            if ($url !== '' && ! $this->urlLooksValid($url)) {
                $this->error("URL does not look like a git repo: {$url}");

                return self::FAILURE;
            }
            $changes['git_repository_url'] = $url === '' ? null : $url;
        }

        $branch = $this->option('branch');
        if ($branch !== null) {
            $branch = trim((string) $branch);
            if ($branch === '') {
                // git_branch is NOT NULL in the schema. Reject empty
                // rather than corrupting the row to a null we can't
                // store. Use 'main' or 'master' explicitly to "reset".
                $this->error('--branch cannot be empty (the column is NOT NULL).');

                return self::FAILURE;
            }
            $changes['git_branch'] = $branch;
        }

        $path = $this->option('path');
        if ($path !== null) {
            $path = trim((string) $path, " \t\n\r/");
            $changes['repository_path'] = $path === '' ? null : $path;
        }

        if ($changes === []) {
            $this->error('Pass at least one of --url, --branch, --path.');

            return self::FAILURE;
        }

        $diff = [];
        foreach ($changes as $col => $val) {
            $diff[$col] = ['from' => $site->getAttribute($col), 'to' => $val];
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun) {
            $site->fill($changes)->save();
        }

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'dry_run' => $dryRun,
            'changes' => $diff,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$site->name}:");
        foreach ($diff as $col => $change) {
            $this->line(sprintf(
                '  %-22s %s → %s',
                $col,
                $this->display($change['from']),
                $this->display($change['to']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Lightweight check — accept SSH-style (git@host:org/repo.git or
     * ssh://) and HTTPS-style URLs. The deploy will catch invalid
     * URLs at clone time; this is just a typo gate.
     */
    private function urlLooksValid(string $url): bool
    {
        if (preg_match('#^https?://[^\s]+#i', $url)) {
            return true;
        }
        if (preg_match('#^ssh://[^\s]+#i', $url)) {
            return true;
        }
        if (preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._/-]+#', $url)) {
            return true;
        }

        return false;
    }

    private function display(mixed $v): string
    {
        if ($v === null) {
            return '<fg=gray>null</>';
        }

        return (string) $v;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
