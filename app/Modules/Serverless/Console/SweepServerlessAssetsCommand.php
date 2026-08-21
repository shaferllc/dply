<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Modules\Serverless\Services\ServerlessAssetGarbageCollector;
use Illuminate\Console\Command;

/**
 * Reclaim superseded asset objects and record what each site is storing.
 *
 *   php artisan dply:serverless:sweep-assets
 *   php artisan dply:serverless:sweep-assets --dry-run
 *
 * Runs before the usage collector each day, since the collector reads the
 * storage figure this sweep writes.
 */
class SweepServerlessAssetsCommand extends Command
{
    protected $signature = 'dply:serverless:sweep-assets
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete superseded serverless assets and measure per-site storage for billing.';

    public function handle(ServerlessAssetGarbageCollector $collector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $collector->sweep($dryRun);

        $this->info(sprintf(
            '%s %d site(s) — %s stored (+%s in app buckets), %d object(s) %s (%s reclaimed)',
            $dryRun ? '[dry-run] Swept' : 'Swept',
            $result['sites'],
            $this->bytes($result['bytes']),
            $this->bytes($result['app_bytes']),
            $result['deleted'],
            $dryRun ? 'would be deleted' : 'deleted',
            $this->bytes($result['reclaimed_bytes']),
        ));

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 2).' GiB';
        }

        return round($bytes / 1024 ** 2, 1).' MiB';
    }
}
