<?php

declare(strict_types=1);

namespace App\Modules\Cache\Console;

use App\Models\CloudDatabase;
use App\Modules\Cache\Models\ManagedCache;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Wrap every existing managed Redis cluster in a ManagedCache.
 *
 * The second half of docs/adr/dply-cache.md decision 10: rather than leave two
 * front doors open for a byte-identical resource, `engine=redis` rows created
 * through /cloud/databases become dedicated caches and appear at /caches.
 *
 * Nothing moves. No provider call is made, no cluster restarts, and the
 * `cloud_databases` row is untouched — a `ManagedCache` is created pointing at
 * it. Idempotent, so it can be run again after a partial run.
 *
 * Adopted rows are NOT stamped `grandfathered_at` here: these clusters were
 * already billed by CloudResourceCostCalculator before this command existed, so
 * excluding them would be a price *cut* nobody asked for. The grandfather stamp
 * belongs to the M4 fold-in of the per-function Valkey clusters, which were
 * genuinely free when they were provisioned.
 */
class AdoptRedisDatabasesCommand extends Command
{
    protected $signature = 'dply:cache:adopt-redis {--dry-run : List what would be adopted and change nothing}';

    protected $description = 'Wrap existing managed Redis clusters in a dply Cache so they appear at /caches.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $adopted = 0;
        $skipped = 0;

        CloudDatabase::query()
            ->where('engine', CloudDatabase::ENGINE_REDIS)
            ->whereNotNull('organization_id')
            ->orderBy('created_at')
            ->each(function (CloudDatabase $database) use (&$adopted, &$skipped, $dryRun): void {
                $existing = ManagedCache::query()
                    ->where('cloud_database_id', $database->id)
                    ->exists();

                if ($existing) {
                    $skipped++;

                    return;
                }

                $name = $this->uniqueName((string) $database->organization_id, (string) $database->name);

                $this->line(($dryRun ? '[dry-run] ' : '').'Adopting '.$database->name.' as cache "'.$name.'"');

                if ($dryRun) {
                    $adopted++;

                    return;
                }

                ManagedCache::query()->create([
                    'organization_id' => $database->organization_id,
                    'name' => $name,
                    'tier' => ManagedCache::TIER_DEDICATED,
                    'status' => ManagedCache::STATUS_ACTIVE,
                    'cloud_database_id' => $database->id,
                    'meta' => ['adopted_from' => 'cloud_databases'],
                ]);

                $adopted++;
            });

        $this->info(($dryRun ? 'Would adopt ' : 'Adopted ').$adopted.' cluster(s); '.$skipped.' already adopted.');

        return self::SUCCESS;
    }

    private function uniqueName(string $organizationId, string $name): string
    {
        // Cluster names carry a `cache-` prefix when dply created them for this
        // product; strip it so a round trip does not accumulate prefixes.
        $base = Str::slug(Str::after($name, 'cache-') ?: $name) ?: 'cache';
        $candidate = $base;
        $suffix = 2;

        while (ManagedCache::query()
            ->where('organization_id', $organizationId)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
