<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Jobs\TeardownEdgeSiteJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Tear down a preview deployment by parent + branch.
 *
 *   dply:edge:preview:teardown <parent> --branch=feature/x
 *
 * Designed for CI to call on PR close. Idempotent — exits success
 * with a "no preview found" message when the preview was already
 * cleaned up (so a duplicate "PR closed" webhook doesn't fail the
 * pipeline).
 */
class EdgePreviewTeardownCommand extends Command
{
    protected $signature = 'dply:edge:preview:teardown
        {parent : Parent site ID, slug, or name}
        {--branch= : Branch whose preview should be torn down}';

    protected $description = 'Tear down a preview edge deployment by parent + branch.';

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

        $preview = CreateEdgePreviewSite::findExisting($parent, $branch);
        if ($preview === null) {
            $this->info("No preview found for branch {$branch} — already torn down.");

            return self::SUCCESS;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);
        $this->info(sprintf('Preview teardown queued for %s (branch=%s).', $preview->name, $branch));

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
