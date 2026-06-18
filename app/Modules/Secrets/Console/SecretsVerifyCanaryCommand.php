<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * Prove APP_KEY (plus APP_PREVIOUS_KEYS) can still decrypt real data on a given
 * connection — used by the restore drill after importing a dump into a scratch
 * DB, and as a cheap standalone health check against the live DB. Exits non-zero
 * on a decrypt failure so cron/drill can alert.
 */
class SecretsVerifyCanaryCommand extends Command
{
    protected $signature = 'secrets:verify-canary {--connection= : DB connection to read from}';

    protected $description = 'Decrypt a known encrypted column to prove APP_KEY round-trips.';

    public function handle(): int
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = config('secret_vault.canary.model');
        $column = (string) config('secret_vault.canary.column');
        $connection = $this->option('connection');

        if (! class_exists($modelClass) || $column === '') {
            $this->error('Canary model/column is not configured.');

            return self::FAILURE;
        }

        $query = $modelClass::query();
        if ($connection) {
            $query->getModel()->setConnection((string) $connection);
        }

        $row = $query->whereNotNull($column)->first();
        if ($row === null) {
            $this->warn("No row with a non-null {$column} to verify — treating as inconclusive PASS.");

            return self::SUCCESS;
        }

        try {
            $value = $row->{$column}; // triggers the encrypted cast → decrypts
        } catch (DecryptException $e) {
            $this->error("Canary decrypt FAILED on {$modelClass}.{$column}: ".$e->getMessage());

            return self::FAILURE;
        }

        if (! is_string($value) || $value === '') {
            $this->error("Canary decrypted to empty/non-string on {$modelClass}.{$column}.");

            return self::FAILURE;
        }

        $this->info("Canary OK: decrypted {$modelClass}.{$column} (".strlen($value).' bytes).');

        return self::SUCCESS;
    }
}
