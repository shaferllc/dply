<?php

declare(strict_types=1);

namespace App\Modules\Snapshots\Console;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Models\Snapshot;
use App\Modules\Snapshots\Services\SnapshotDestinationFactory;
use App\Modules\Snapshots\Services\SnapshotService;
use Illuminate\Console\Command;

/**
 * dply:snapshot:take <site> [--reason=...] [--destination=auto|local|s3] [--user=email]
 *
 * Granular CLI per Q21 — wraps the SnapshotService for CI use cases:
 * "before deploying, snap the prod DB; if the deploy fails, restore."
 *
 * Defaults to the preferred destination (S3 when configured, local
 * otherwise) so a CI pipeline reading a single env var gets durable
 * backups for free; --destination=local forces the transient path
 * for cases where the operator deliberately wants the 7-day TTL.
 */
class SnapshotTakeCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:snapshot:take
        {site : Site name or slug to snapshot}
        {--reason=manual : Snapshot reason (manual|pre_destructive_command|scheduled)}
        {--destination=auto : auto (preferred) | local | s3}
        {--user= : User email to attribute the snapshot to (defaults to site owner)}
        {--json : Emit a JSON envelope on stdout}';

    protected $description = 'Take a database snapshot of a dply-managed site.';

    public function handle(SnapshotService $service, SnapshotDestinationFactory $destinations): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));

        $destination = match ($this->option('destination')) {
            'local' => $destinations->localDisk(),
            's3' => $destinations->s3() ?? null,
            default => $destinations->preferred(),
        };

        if ($destination === null) {
            $this->error('S3 destination requested but no bucket is configured. Set DPLY_SNAPSHOT_S3_BUCKET or use --destination=local.');

            return self::FAILURE;
        }

        $reason = $this->normaliseReason((string) $this->option('reason'));

        try {
            $snapshot = $service->take(
                site: $site,
                destination: $destination,
                reason: $reason,
                userId: $caller?->getKey(),
            );
        } catch (\Throwable $e) {
            $this->error('Snapshot failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'snapshot_id' => $snapshot->id,
                'destination' => $snapshot->destination,
                's3_bucket' => $snapshot->s3_bucket,
                's3_key' => $snapshot->s3_key,
                'local_path' => $snapshot->local_path,
                'bytes' => $snapshot->bytes,
                'engine' => $snapshot->engine,
                'reason' => $snapshot->reason,
                'expires_at' => $snapshot->expires_at?->toISOString(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Snapshot saved: snap-{$snapshot->id} ({$snapshot->destination}, ".number_format(($snapshot->bytes ?? 0) / 1024, 1).' KB)');
        }

        return self::SUCCESS;
    }

    private function normaliseReason(string $raw): string
    {
        return in_array($raw, [
            Snapshot::REASON_MANUAL,
            Snapshot::REASON_PRE_DESTRUCTIVE_COMMAND,
            Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
            Snapshot::REASON_SCHEDULED,
        ], true) ? $raw : Snapshot::REASON_MANUAL;
    }
}
