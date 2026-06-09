<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Secrets\RestoreTarget;
use App\Services\Secrets\Scope;
use App\Services\Secrets\SecretVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Restore drill (W1) — runs ONLY on the isolated drill host (the one place that
 * holds the age identity + the prod APP_KEY restored from escrow). Proves the
 * whole chain works before we ever need it: decrypt the newest db-dump → import
 * into a scratch DB → verify the canary decrypts under APP_KEY. Pings the
 * dead-man's-switch on success so a stalled drill is noticed.
 *
 * The dump is produced with `pg_dump --clean --if-exists`, so importing into an
 * existing scratch DB is idempotent.
 */
class SecretsRestoreDrillCommand extends Command
{
    protected $signature = 'secrets:restore-drill {--scope=platform}';

    protected $description = 'Prove the newest DB backup restores and APP_KEY still decrypts it.';

    public function handle(SecretVault $vault): int
    {
        $scope = Scope::fromKey((string) $this->option('scope'));
        $conn = (string) config('secret_vault.drill.connection');
        $cfg = (array) config("database.connections.{$conn}");

        if (($cfg['driver'] ?? null) !== 'pgsql') {
            $this->error("Drill connection '{$conn}' is not a pgsql scratch DB — refusing.");

            return self::FAILURE;
        }

        $ref = $vault->latest($scope, 'db-dump');
        if ($ref === null) {
            $this->error('No db-dump escrow found to drill.');

            return self::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dply-drill-').'.sql';

        try {
            $vault->restore($ref, RestoreTarget::envFile($tmp, force: true));

            $psql = Process::timeout(900)
                ->env(['PGPASSWORD' => (string) ($cfg['password'] ?? '')])
                ->run([
                    'psql',
                    '--host='.(string) ($cfg['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($cfg['port'] ?? 5432),
                    '--username='.(string) ($cfg['username'] ?? ''),
                    '--dbname='.(string) ($cfg['database'] ?? ''),
                    '--set=ON_ERROR_STOP=1',
                    '--file='.$tmp,
                ]);

            if (! $psql->successful()) {
                $this->error('Scratch import failed: '.trim($psql->errorOutput()));

                return self::FAILURE;
            }
        } finally {
            @unlink($tmp);
        }

        $canary = $this->call('secrets:verify-canary', ['--connection' => $conn]);
        if ($canary !== self::SUCCESS) {
            $this->error('Canary failed against the restored scratch DB.');

            return self::FAILURE;
        }

        $this->info("Restore drill OK: {$ref->key} imported + canary decrypted.");
        $this->pingDms();

        return self::SUCCESS;
    }

    private function pingDms(): void
    {
        $url = config('secret_vault.dms.drill');
        if (is_string($url) && $url !== '') {
            try {
                Http::timeout(10)->get($url);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }
}
