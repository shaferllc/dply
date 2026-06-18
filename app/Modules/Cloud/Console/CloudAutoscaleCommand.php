<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Actions\Cloud\ConfigureCloudAutoscaling;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enable / configure / disable CPU-target autoscaling for a Cloud site.
 *
 *   dply:cloud:autoscale --site=<id|slug|name> --min=1 --max=5 --cpu=70
 *   dply:cloud:autoscale --site=<id|slug|name> --off
 *
 * Persists meta.container.autoscaling and queues SyncCloudScalingJob,
 * which pushes the autoscaling block into the backend's app spec and
 * rolls a deploy.
 *
 * When autoscaling is enabled it supersedes the fixed instance count
 * set via dply:cloud:scale — the two are mutually exclusive on the
 * backend.
 */
class CloudAutoscaleCommand extends Command
{
    protected $signature = 'dply:cloud:autoscale
        {--site= : Site ID, slug, or name}
        {--min= : Minimum instance count (>= 1)}
        {--max= : Maximum instance count (>= min)}
        {--cpu= : Target CPU percent (1-100)}
        {--off : Disable autoscaling and revert to the fixed instance count}';

    protected $description = 'Configure or disable CPU-target autoscaling for a Cloud container site.';

    public function handle(): int
    {
        $needle = trim((string) $this->option('site'));
        if ($needle === '') {
            $this->error('--site=<id|slug|name> is required.');

            return self::FAILURE;
        }

        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $payload = [];
        if ($this->option('off')) {
            $payload['enabled'] = false;
        } else {
            $payload['enabled'] = true;
            foreach (['min' => 'min_instances', 'max' => 'max_instances', 'cpu' => 'cpu_percent'] as $opt => $key) {
                $value = $this->option($opt);
                if (is_string($value) && $value !== '') {
                    $payload[$key] = (int) $value;
                }
            }
        }

        try {
            $config = (new ConfigureCloudAutoscaling)->handle($site, $payload);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $config['enabled']) {
            $fixed = is_int($site->fresh()?->meta['container']['instance_count'] ?? null)
                ? (int) $site->fresh()->meta['container']['instance_count']
                : 1;
            $this->info(sprintf(
                'Autoscaling disabled for %s — reverting to a fixed %d instance(s). Sync queued.',
                $site->name,
                $fixed,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Autoscaling enabled for %s — %d-%d instances at %d%% CPU target. Sync queued.',
            $site->name,
            $config['min_instances'],
            $config['max_instances'],
            $config['cpu_percent'],
        ));
        $this->line('  Autoscaling supersedes any fixed instance count set via dply:cloud:scale.');

        return self::SUCCESS;
    }
}
