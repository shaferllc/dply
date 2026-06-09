<?php

/**
 * Purge encrypted column values that no longer decrypt with the current
 * APP_KEY. Run via `php scripts/purge-invalid-mac.php`.
 *
 * Walks every (model, column) pair where the model declares an `encrypted`
 * cast, reads the raw row through the DB layer (bypassing Eloquent so the
 * cast doesn't auto-throw), and attempts decryption. On DecryptException
 * — i.e. "MAC is invalid" — sets the column to NULL.
 *
 * Safe to re-run. Reports a per-column tally at the end.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each entry: [table, primary_key, columns_to_purge].
 * Mirrors the `'encrypted'` / `'encrypted:array'` casts in app/Models/*.
 */
$targets = [
    ['site_processes',                                ['env_vars']],
    ['servers',                                       ['ssh_private_key', 'ssh_operational_private_key', 'ssh_recovery_private_key']],
    ['server_database_extra_users',                   ['password']],
    ['server_database_admin_credentials',             ['mysql_root_password', 'postgres_password']],
    ['workspace_variables',                           ['env_value']],
    ['notification_webhook_destinations',             ['webhook_url']],
    ['site_certificates',                             ['certificate_pem', 'private_key_pem', 'chain_pem', 'csr_pem']],
    ['backup_configurations',                         ['config']],
    ['supervisor_programs',                           ['env_vars']],
    ['server_create_drafts',                          ['payload']],
    ['server_databases',                              ['password']],
    ['server_cache_services',                         ['auth_password']],
    ['organization_supervisor_program_templates',     ['env_vars']],
    ['notification_channels',                         ['config']],
    ['provider_credentials',                          ['credentials']],
    ['sites',                                         ['git_deploy_key_private', 'webhook_secret', 'env_file_content']],
];

$totalPurged = 0;
$totalChecked = 0;

foreach ($targets as [$table, $columns]) {
    if (! Schema::hasTable($table)) {
        echo "skip {$table} (table missing)\n";

        continue;
    }

    foreach ($columns as $column) {
        if (! Schema::hasColumn($table, $column)) {
            echo "skip {$table}.{$column} (column missing)\n";

            continue;
        }

        // Stream by id to avoid loading the whole table.
        $purgedHere = 0;
        $checkedHere = 0;
        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, &$purgedHere, &$checkedHere) {
                foreach ($rows as $row) {
                    $checkedHere++;
                    $value = $row->{$column};
                    if ($value === null || $value === '') {
                        continue;
                    }
                    try {
                        Crypt::decryptString($value);
                    } catch (DecryptException) {
                        try {
                            DB::table($table)->where('id', $row->id)->update([$column => null]);
                            $purgedHere++;
                        } catch (QueryException $e) {
                            // Column is NOT NULL — can't null it, delete the row.
                            if (str_contains($e->getMessage(), 'not-null constraint')
                                || str_contains($e->getMessage(), 'cannot be null')) {
                                DB::table($table)->where('id', $row->id)->delete();
                                $purgedHere++;
                            } else {
                                throw $e;
                            }
                        }
                    }
                }
            });

        $totalChecked += $checkedHere;
        $totalPurged += $purgedHere;
        $tag = $purgedHere > 0 ? '✗' : '✓';
        echo sprintf("%s %-50s checked=%-6d purged=%d\n", $tag, $table.'.'.$column, $checkedHere, $purgedHere);
    }
}

echo "\n";
echo "TOTAL checked = {$totalChecked}\n";
echo "TOTAL purged  = {$totalPurged}\n";
