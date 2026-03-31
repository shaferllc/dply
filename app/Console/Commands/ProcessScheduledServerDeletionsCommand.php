<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\DeleteServerAction;
use App\Models\Server;
use Illuminate\Console\Command;

class ProcessScheduledServerDeletionsCommand extends Command
{
    protected $signature = 'dply:process-scheduled-server-deletions';

    protected $description = 'Delete servers whose scheduled_deletion_at is due';

    public function handle(DeleteServerAction $deleteServer): int
    {
        $query = Server::query()
            ->whereNotNull('scheduled_deletion_at')
            ->where('scheduled_deletion_at', '<=', now());

        $count = 0;
        $query->each(function (Server $server) use ($deleteServer, &$count): void {
            $meta = $server->meta ?? [];
            $reason = $meta['scheduled_deletion_reason'] ?? null;
            $auditExtras = ['scheduled_completion' => true];
            if (is_string($reason) && $reason !== '') {
                $auditExtras['reason'] = $reason;
            }
            $deleteServer->execute(
                $server,
                null,
                $auditExtras,
                __('This server was removed automatically at the end of its scheduled removal window.'),
            );
            $count++;
        });

        if ($count > 0) {
            $this->info("Deleted {$count} scheduled server(s).");
        }

        return self::SUCCESS;
    }
}
