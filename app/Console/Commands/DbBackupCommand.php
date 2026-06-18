<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Secrets\Services\Scope;
use App\Modules\Secrets\Services\SecretVault;
use App\Modules\Secrets\Services\Sources\DbDumpSource;
use Illuminate\Console\Command;

/**
 * Back up the control-plane database: pg_dump (in-process) → age-encrypt →
 * vault stores, via {@see SecretVault}. App-native (no bash). `--stdout` prints
 * the plaintext dump instead (debugging / piping).
 */
class DbBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--connection= : DB connection (defaults to the app default)}
        {--stdout : print the plaintext dump to stdout instead of escrowing}';

    protected $description = 'Back up the control-plane database (age-encrypted) to the vault.';

    public function handle(SecretVault $vault): int
    {
        $source = new DbDumpSource($this->option('connection'));

        try {
            if ($this->option('stdout')) {
                $this->output->write($source->gather(Scope::platform()));

                return self::SUCCESS;
            }

            $ref = $vault->escrow($source, Scope::platform());
        } catch (\Throwable $e) {
            $this->error('DB backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backed up {$ref->key}");
        $this->line('  stores: '.implode(', ', $ref->stores));

        return self::SUCCESS;
    }
}
