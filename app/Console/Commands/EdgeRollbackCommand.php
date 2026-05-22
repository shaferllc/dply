<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RedeployEdgeSiteJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Roll an image-mode edge site back to a previously deployed
 * image tag.
 *
 *   dply:edge:rollback <site> [--image=<tag>] [--steps=N]
 *
 * Without --image, rolls back to the most recent prior tag in
 * meta.container.image_history (i.e. one step back). --steps=N
 * rolls back N positions in history. --image=<tag> jumps to a
 * specific historical tag.
 *
 * Source-mode sites have no image history (they redeploy from
 * git on every push), so this command rejects them — use
 * git revert + dply:edge:deploy for source rollbacks instead.
 */
class EdgeRollbackCommand extends Command
{
    protected $signature = 'dply:edge:rollback
        {site : Site ID, slug, or name}
        {--image= : Specific image tag to roll back to}
        {--steps=1 : How many history positions to roll back (default 1)}';

    protected $description = 'Roll an image-mode edge site back to a previous image tag.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! is_string($site->container_backend) || $site->container_backend === '') {
            $this->error("Site {$site->name} is not an edge container site.");

            return self::FAILURE;
        }

        if (is_array($site->meta['container']['source'] ?? null)) {
            $this->error('Source-mode sites have no image history. Use git revert + dply:edge:deploy to roll back.');

            return self::FAILURE;
        }

        $target = $this->resolveTargetImage($site);
        if ($target === null) {
            return self::FAILURE;
        }

        if ($target === $site->container_image) {
            $this->info("Site {$site->name} is already on {$target} — nothing to roll back.");

            return self::SUCCESS;
        }

        RedeployEdgeSiteJob::dispatch($site->id, $target);
        $this->info(sprintf('Rollback queued: %s → %s.', $site->name, $target));

        return self::SUCCESS;
    }

    private function resolveTargetImage(Site $site): ?string
    {
        $explicit = $this->option('image');
        if (is_string($explicit) && $explicit !== '') {
            return trim($explicit);
        }

        $stepsRaw = $this->option('steps');
        $steps = is_string($stepsRaw) && ctype_digit($stepsRaw) ? max(1, (int) $stepsRaw) : 1;

        $history = is_array($site->meta['container']['image_history'] ?? null)
            ? array_values($site->meta['container']['image_history'])
            : [];

        if ($history === []) {
            $this->error('No image history recorded for this site — pass --image=<tag> explicitly.');

            return null;
        }

        // History is stored chronologically (most recent last). The
        // current image is the last entry; one step back is the entry
        // just before it.
        $current = $site->container_image;
        $reversed = array_reverse($history);
        $found = 0;
        foreach ($reversed as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $img = (string) ($entry['image'] ?? '');
            if ($img === '' || $img === $current) {
                continue;
            }
            $found++;
            if ($found === $steps) {
                return $img;
            }
        }

        $this->error(sprintf(
            'Image history only has %d entr%s before the current tag — cannot step back %d.',
            $found,
            $found === 1 ? 'y' : 'ies',
            $steps,
        ));

        return null;
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
