<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerCreateDraft;
use Illuminate\Console\Command;

class PruneServerCreateDraftsCommand extends Command
{
    protected $signature = 'dply:prune-server-create-drafts';

    protected $description = 'Delete abandoned create-server wizard drafts whose expires_at is in the past.';

    public function handle(): int
    {
        $cutoff = now();

        $deleted = ServerCreateDraft::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff)
            ->delete();

        $this->info('Deleted '.$deleted.' expired server-create draft(s).');

        return self::SUCCESS;
    }
}
