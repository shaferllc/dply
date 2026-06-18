<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Models\Snapshot;
use Illuminate\Console\Command;

/**
 * dply:snapshot:list <site> [--limit=25] [--json]
 *
 * Pure-read CLI for the per-site snapshot inventory. Mirrors the
 * Database sub-tab's snapshot list — no SSH calls, just a DB query.
 */
class SnapshotListCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:snapshot:list
        {site : Site name or slug}
        {--limit=25 : Maximum snapshots to list (newest first)}
        {--json : Emit a JSON array on stdout}';

    protected $description = 'List snapshots for a dply-managed site.';

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $snapshots = Snapshot::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($this->option('json')) {
            $this->line($snapshots->map(fn (Snapshot $s) => [
                'id' => $s->id,
                'destination' => $s->destination,
                's3_bucket' => $s->s3_bucket,
                's3_key' => $s->s3_key,
                'local_path' => $s->local_path,
                'bytes' => $s->bytes,
                'engine' => $s->engine,
                'reason' => $s->reason,
                'expires_at' => $s->expires_at?->toISOString(),
                'created_at' => $s->created_at->toISOString(),
            ])->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($snapshots->isEmpty()) {
            $this->line('No snapshots.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Destination', 'Reason', 'Size', 'Engine', 'Age', 'Expires'],
            $snapshots->map(fn (Snapshot $s) => [
                'snap-'.$s->id,
                $s->destination,
                $s->reason,
                number_format(($s->bytes ?? 0) / 1024, 1).' KB',
                $s->engine,
                $s->created_at->diffForHumans(),
                $s->expires_at?->diffForHumans() ?? 'never',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
