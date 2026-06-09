<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

class ProcessScheduledSiteDeletionsCommand extends Command
{
    protected $signature = 'dply:process-scheduled-site-deletions';

    protected $description = 'Delete sites whose scheduled_deletion_at is due.';

    public function handle(): int
    {
        $query = Site::query()
            ->whereNotNull('scheduled_deletion_at')
            ->where('scheduled_deletion_at', '<=', now());

        $count = 0;
        $query->each(function (Site $site) use (&$count): void {
            $organization = $site->server?->organization ?? $site->organization;
            $meta = $site->meta ?? [];
            $reason = is_string($meta['scheduled_deletion_reason'] ?? null) ? $meta['scheduled_deletion_reason'] : null;

            $snapshot = [
                'name' => $site->name,
                'slug' => $site->slug,
                'server_id' => (string) $site->server_id,
                'type' => $site->type instanceof \BackedEnum ? $site->type->value : (string) $site->type,
                'runtime' => $site->runtime,
                'git_repository_url' => $site->git_repository_url,
                'scheduled_completion' => true,
            ];
            if ($reason !== null) {
                $snapshot['reason'] = $reason;
            }

            $site->delete();

            if ($organization) {
                audit_log($organization, null, 'site.deleted', $site, $snapshot, null);
            }

            $count++;
        });

        if ($count > 0) {
            $this->info("Deleted {$count} scheduled site(s).");
        }

        return self::SUCCESS;
    }
}
