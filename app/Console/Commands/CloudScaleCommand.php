<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RedeployCloudSiteJob;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\CloudScalingConfig;
use Illuminate\Console\Command;

/**
 * Set the desired instance count for an cloud site.
 *
 *   dply:cloud:scale <site> --instances=N
 *
 * Persists meta.container.instance_count on the Site, calls
 * backend->updateEnvVars() (which re-pushes the spec including
 * the new instance count on DO App Platform), then queues a
 * redeploy so the change rolls out.
 *
 * On AWS App Runner, instance_count is recorded as the operator's
 * intent — App Runner's actual scaling is controlled by an
 * AutoScalingConfiguration ARN, which we surface in the
 * dashboard but don't manipulate here.
 */
class CloudScaleCommand extends Command
{
    protected $signature = 'dply:cloud:scale
        {site : Site ID, slug, or name}
        {--instances= : Desired instance count (1-50)}
        {--no-redeploy : Persist the change only, do not queue a redeploy}';

    protected $description = 'Set the desired instance count for an cloud container site.';

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

        $raw = $this->option('instances');
        if (! is_string($raw) || ! ctype_digit($raw)) {
            $this->error('--instances=<positive integer> is required.');

            return self::FAILURE;
        }
        $instances = (int) $raw;
        if ($instances < 1 || $instances > 50) {
            $this->error('--instances must be between 1 and 50.');

            return self::FAILURE;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'instance_count' => $instances,
        ]);
        $site->update(['meta' => $meta]);

        // A fixed instance count and autoscaling are mutually
        // exclusive — warn that autoscaling will keep winning.
        if (CloudScalingConfig::autoscalingEnabled($site->fresh())) {
            $this->warn(
                'Autoscaling is enabled for this site — it supersedes a fixed instance count. '
                .'The value is recorded but will not take effect until autoscaling is disabled '
                .'(dply:cloud:autoscale --site='.$site->name.' --off).',
            );
        }

        $backend = CloudRouter::backendFor($site->fresh());
        $credential = CloudRouter::credentialFor($site->fresh());
        if ($backend !== null && $credential !== null) {
            try {
                // updateEnvVars re-pushes the whole service spec on
                // DO, which includes instance_count. Reusing it here
                // avoids a third backend verb just for scaling.
                $backend->updateEnvVars($site->fresh(), $credential);
            } catch (\Throwable $e) {
                $this->error('Backend rejected scale update: '.$e->getMessage());
                $this->info('Site meta updated locally — fix the backend issue and re-run.');

                return self::FAILURE;
            }
        }

        if (! $this->option('no-redeploy')) {
            RedeployCloudSiteJob::dispatch($site->id);
            $this->info(sprintf('Scaled %s to %d instance(s); redeploy queued.', $site->name, $instances));
        } else {
            $this->info(sprintf('Scaled %s to %d instance(s) (no redeploy queued).', $site->name, $instances));
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
