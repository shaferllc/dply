<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'dply:prune-audit-logs';

    protected $description = 'Delete audit_logs rows older than the configured retention window (config audit.retention_days).';

    public function handle(): int
    {
        $days = max(30, (int) config('audit.retention_days', 365));
        $cutoff = now()->subDays($days);

        $deleted = AuditLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info('Deleted '.$deleted.' audit_logs row(s) older than '.$days.' days.');

        return self::SUCCESS;
    }
}
