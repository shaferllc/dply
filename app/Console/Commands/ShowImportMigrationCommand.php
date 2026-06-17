<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use Illuminate\Console\Command;

/**
 * Per-migration detail view for ops triage. Renders the full step plan with
 * status pills and per-step error messages.
 */
class ShowImportMigrationCommand extends Command
{
    protected $signature = 'dply:imports:show {migration : ulid of the ImportServerMigration}';

    protected $description = 'Show per-step detail for a single import migration.';

    public function handle(): int
    {
        $migration = ImportServerMigration::query()
            ->with(['organization', 'targetServer', 'siteMigrations.steps', 'steps'])
            ->find($this->argument('migration'));

        if ($migration === null) {
            $this->error('Migration not found.');

            return self::FAILURE;
        }

        $this->line(sprintf('<info>Migration %s</info>', $migration->id));
        $this->line(sprintf('  Source:           %s server %d', $migration->source, $migration->source_server_id));
        $this->line(sprintf('  Status:           %s', $migration->status));
        $this->line(sprintf('  Org:              %s', $migration->organization->name ?? $migration->organization_id));
        $this->line(sprintf('  Target server:    %s', $migration->targetServer->name ?? '(none)'));
        $this->line(sprintf('  Started / done:   %s → %s',
            optional($migration->started_at)->toDateTimeString() ?? '-',
            optional($migration->completed_at)->toDateTimeString() ?? '-',
        ));
        $this->line(sprintf('  SSH key pushed:   %s', optional($migration->ssh_key_pushed_at)->diffForHumans() ?? 'not pushed'));
        $this->line(sprintf('  SSH key revoked:  %s', optional($migration->ssh_key_revoked_at)->diffForHumans() ?? 'still installed'));
        if ($migration->paused_nudge_sent_at !== null) {
            $this->line(sprintf('  Paused nudge sent: %s', $migration->paused_nudge_sent_at->diffForHumans()));
        }
        if ($migration->failure_summary) {
            $this->line('  Failure summary:');
            $this->line('    '.str_replace("\n", "\n    ", $migration->failure_summary));
        }
        $this->newLine();

        // Server-level steps.
        $serverSteps = $migration->steps->whereNull('import_site_migration_id')->sortBy('sequence');
        if ($serverSteps->isNotEmpty()) {
            $this->line('<comment>Server-level steps:</comment>');
            foreach ($serverSteps as $step) {
                $this->renderStep($step, '  ');
            }
            $this->newLine();
        }

        foreach ($migration->siteMigrations as $child) {
            $this->line(sprintf('<comment>Site: %s</comment>  (%s, status: %s%s)',
                $child->domain,
                $child->site_type,
                $child->status,
                $child->ssl_strategy ? ', SSL: '.$child->ssl_strategy : '',
            ));
            foreach ($child->steps->sortBy('sequence') as $step) {
                $this->renderStep($step, '  ');
            }
            if ($child->failure_summary) {
                $this->line('  Failure:');
                $this->line('    '.str_replace("\n", "\n    ", $child->failure_summary));
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function renderStep(ImportMigrationStep $step, string $indent): void
    {
        $icon = match ($step->status) {
            ImportMigrationStep::STATUS_SUCCEEDED => '<fg=green>✓</>',
            ImportMigrationStep::STATUS_FAILED => '<fg=red>✗</>',
            ImportMigrationStep::STATUS_RUNNING => '<fg=blue>•</>',
            ImportMigrationStep::STATUS_SKIPPED => '<fg=gray>—</>',
            default => '<fg=gray>·</>',
        };
        $this->line(sprintf('%s%s %s  (%s, attempts: %d)',
            $indent,
            $icon,
            $step->step_key,
            $step->status,
            $step->attempts,
        ));
        if ($step->status === ImportMigrationStep::STATUS_FAILED && $step->error_message) {
            $this->line($indent.'    <fg=red>'.$step->error_message.'</>');
        }
    }
}
