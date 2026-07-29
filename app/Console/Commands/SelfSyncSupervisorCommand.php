<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SelfManage\SelfSupervisorSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Merge-sync control-plane supervisor templates from dply.yaml into conf.d.
 */
class SelfSyncSupervisorCommand extends Command
{
    protected $signature = 'dply:self:sync-supervisor
        {--role= : Override role key (web|worker.primary|worker.replica)}
        {--dry-run : Show what would change without writing}
        {--adopt-collisions : Strip colliding managed program names from sibling conf files}
        {--force : Run even when DPLY_RUNTIME is all/local or templates are disabled}';

    protected $description = 'Sync control-plane supervisor programs from dply.yaml templates (merge-safe).';

    public function handle(SelfSupervisorSync $sync): int
    {
        try {
            $result = $sync->sync(
                roleOverride: $this->option('role') ? (string) $this->option('role') : null,
                dryRun: (bool) $this->option('dry-run'),
                adoptCollisions: (bool) $this->option('adopt-collisions'),
                force: (bool) $this->option('force'),
            );
        } catch (Throwable $e) {
            $this->error('[dply] supervisor sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['skipped']) {
            $this->line('[dply] '.$result['message']);

            return self::SUCCESS;
        }

        $this->line('[dply] role='.$result['role']);
        if ($result['source'] !== null) {
            $this->line('[dply] source='.$result['source']);
        }
        if ($result['dest'] !== null) {
            $this->line('[dply] dest='.$result['dest']);
        }
        if ($result['managed'] !== []) {
            $this->line('[dply] managed: '.implode(', ', $result['managed']));
        }
        if ($result['preserved'] !== []) {
            $this->line('[dply] preserved local: '.implode(', ', $result['preserved']));
        }
        if ($result['collisions'] !== []) {
            foreach ($result['collisions'] as $name => $file) {
                $this->warn("[dply] collision: {$name} → {$file}");
            }
        }
        $this->line('[dply] '.$result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
