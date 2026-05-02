<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `php_version` column from the sites table per the strategy
 * memo's clean-data-model decision: "drop php_version column entirely.
 * Add top-level columns: runtime, runtime_version, …"
 *
 * The earlier migration (2026_05_01_150000_add_runtime_columns_to_sites_table)
 * already backfilled runtime_version from php_version. This migration:
 *
 *   1. Defensive backfill — for any site with php_version set but no
 *      runtime_version (e.g. created between the previous migration and
 *      this one), copy across.
 *   2. Backfill `runtime` for any PHP-shaped row that's still null,
 *      derived from the existing `type` enum so phpVersion() and
 *      runtimeKey() return consistent values for legacy rows.
 *   3. Drop the php_version column.
 *
 * Pre-launch — no real users — so this is safe per the memo: "we are
 * starting out so we dont need legacy stuff." The Site::phpVersion()
 * accessor already prefers runtime_version, so consumers that called
 * $site->phpVersion() keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')
            ->whereNull('runtime_version')
            ->whereNotNull('php_version')
            ->update(['runtime_version' => DB::raw('php_version')]);

        DB::table('sites')
            ->whereNull('runtime')
            ->whereNotNull('php_version')
            ->update(['runtime' => 'php']);

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('php_version');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('php_version', 10)->nullable();
        });

        // Restore php_version for PHP sites that have a runtime_version.
        DB::table('sites')
            ->where('runtime', 'php')
            ->whereNotNull('runtime_version')
            ->update(['php_version' => DB::raw('runtime_version')]);
    }
};
