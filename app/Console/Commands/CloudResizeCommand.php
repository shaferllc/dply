<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RedeployCloudSiteJob;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Console\Command;

/**
 * Resize an cloud site's compute tier.
 *
 *   dply:cloud:resize <site> --size=medium
 *
 * Tiers map per backend:
 *   small  → DO basic-xxs / AWS App Runner 256 cpu / 512 mem
 *   medium → DO basic-xs  / AWS 512 / 1024
 *   large  → DO basic-s   / AWS 1024 / 2048
 *   xlarge → DO basic-m   / AWS 2048 / 4096
 *
 * The size lives on meta.container.size_tier. Backend adapters
 * read it on every provision / updateEnvVars push so the change
 * rolls out on the next redeploy.
 */
class CloudResizeCommand extends Command
{
    private const TIERS = ['small', 'medium', 'large', 'xlarge'];

    protected $signature = 'dply:cloud:resize
        {site : Site ID, slug, or name}
        {--size= : Compute tier — small, medium, large, or xlarge}
        {--no-redeploy : Persist the change only, do not queue a redeploy}';

    protected $description = 'Resize an cloud container site to a different compute tier.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! is_string($site->container_backend) || $site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $size = strtolower((string) ($this->option('size') ?? ''));
        if (! in_array($size, self::TIERS, true)) {
            $this->error('--size=<tier> required. Valid: '.implode(', ', self::TIERS));

            return self::FAILURE;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'size_tier' => $size,
        ]);
        $site->update(['meta' => $meta]);

        $backend = CloudRouter::backendFor($site->fresh());
        $credential = CloudRouter::credentialFor($site->fresh());
        if ($backend !== null && $credential !== null) {
            try {
                // updateEnvVars re-pushes the spec on DO including
                // instance_size_slug; reusing it avoids a third
                // backend verb just for resize.
                $backend->updateEnvVars($site->fresh(), $credential);
            } catch (\Throwable $e) {
                $this->error('Backend rejected resize: '.$e->getMessage());
                $this->info('Site meta updated locally — fix the backend issue and re-run.');

                return self::FAILURE;
            }
        }

        if (! $this->option('no-redeploy')) {
            RedeployCloudSiteJob::dispatch($site->id);
            $this->info(sprintf('Resized %s to %s; redeploy queued.', $site->name, $size));
        } else {
            $this->info(sprintf('Resized %s to %s (no redeploy queued).', $site->name, $size));
        }

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
