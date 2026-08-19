<?php

declare(strict_types=1);

namespace App\Modules\Database\Console;

use App\Models\CloudDatabaseTrustedSource;
use App\Modules\Database\Services\TrustedSourceManager;
use Illuminate\Console\Command;

/**
 * Removes expired operator IPs from managed clusters' trusted-source lists.
 *
 * Mirrors dply:revoke-expired-ssh-sessions: temporary access that depends on a
 * human remembering to close it is not temporary access.
 */
class ReapExpiredTrustedSourcesCommand extends Command
{
    protected $signature = 'dply:databases:reap-trusted-sources {--dry-run : Report what would be revoked without calling any provider}';

    protected $description = 'Revoke expired operator IP allowances on managed database clusters.';

    public function handle(TrustedSourceManager $manager): int
    {
        if (! $manager->writesEnabled()) {
            $this->warn('Trusted-source writes are disabled (server_database.trusted_source_writes). Nothing to do.');

            return self::SUCCESS;
        }

        $expired = CloudDatabaseTrustedSource::query()->reapable()->with('cloudDatabase')->get();

        if ($expired->isEmpty()) {
            $this->info('No expired trusted sources.');

            return self::SUCCESS;
        }

        foreach ($expired as $record) {
            $this->line(sprintf(
                '  %s on %s (expired %s)',
                $record->ip_address,
                $record->cloudDatabase->name ?? $record->cloud_database_id,
                $record->expires_at->diffForHumans(),
            ));
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d allowance(s) would be revoked.', $expired->count()));

            return self::SUCCESS;
        }

        $clusters = $manager->reapExpired();

        $this->info(sprintf('Revoked %d allowance(s) across %d cluster(s).', $expired->count(), $clusters));

        return self::SUCCESS;
    }
}
