<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Spawn a per-branch preview deployment for a source-mode edge site.
 *
 *   dply:edge:preview:create <parent> --branch=feature/x [--pr=42]
 *
 * Designed to be called from CI on PR open / sync. The preview site
 * is identified by parent + branch — re-running the command for the
 * same pair returns the existing preview without spawning a duplicate.
 */
class EdgePreviewCreateCommand extends Command
{
    protected $signature = 'dply:edge:preview:create
        {parent : Parent site ID, slug, or name}
        {--branch= : Git branch to deploy as the preview}
        {--pr= : Optional PR number for nicer naming (pr-42-…)}';

    protected $description = 'Spawn a preview deployment branch on the dply edge platform.';

    public function handle(): int
    {
        $needle = (string) $this->argument('parent');
        $parent = $this->resolveSite($needle);
        if ($parent === null) {
            $this->error("Parent site not found: {$needle}");

            return self::FAILURE;
        }

        $branch = trim((string) ($this->option('branch') ?? ''));
        if ($branch === '') {
            $this->error('--branch is required.');

            return self::FAILURE;
        }

        $prRaw = $this->option('pr');
        $prNumber = is_string($prRaw) && $prRaw !== '' && ctype_digit($prRaw) ? (int) $prRaw : null;

        try {
            $preview = (new CreateEdgePreviewSite)->handle($parent, $branch, $prNumber);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Preview ready: %s (slug=%s, branch=%s%s).',
            $preview->name,
            $preview->slug,
            $branch,
            $prNumber !== null ? ', pr=#'.$prNumber : '',
        ));

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
