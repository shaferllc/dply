<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One schedule table for every kind of scheduled capture.
 *
 * `server_backup_schedules` (database dumps, site file archives) becomes
 * `backup_schedules`, and `redis_snapshot_schedules` folds into it as
 * `target_type = 'cache'` with the cache service id as the target. Server
 * images land here in M2 as `target_type = 'server_image'`.
 *
 * Why: docs/adr/backups-as-a-product.md decision 4 makes "distinct protected
 * target" the unit an invoice is computed from, and decision 8 refuses to let
 * that number come from a UNION across tables that have already proven they
 * drift — `redis_snapshot_schedules` was a column-for-column copy of its
 * sibling, made because cloning was easier than generalising.
 *
 * Two shape changes ride along:
 *
 * - `server_cron_job_id` is dropped. It pointed at a `system_managed`
 *   ServerCronJob whose command line was excluded from every crontab by
 *   ServerCronSynchronizer, so it addressed nothing. M0 replaced it with
 *   DispatchDueBackupSchedulesCommand ticking on the control plane.
 * - `server_id` becomes nullable. `Site.server_id` is nullable — serverless,
 *   Edge and Cloud sites have no server — so a schedule protecting one cannot
 *   require a server either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('server_backup_schedules', 'backup_schedules');

        Schema::table('backup_schedules', function (Blueprint $table): void {
            $table->dropColumn('server_cron_job_id');
            $table->char('server_id', 26)->nullable()->change();
        });

        // Cache schedules carry their cache service in a dedicated column; here
        // it is simply the target. `id` is preserved so anything holding one
        // (audit log entries, notification payloads) still resolves.
        foreach (DB::table('redis_snapshot_schedules')->orderBy('id')->cursor() as $legacy) {
            DB::table('backup_schedules')->insert([
                'id' => $legacy->id,
                'server_id' => $legacy->server_id,
                'target_type' => 'cache',
                'target_id' => $legacy->server_cache_service_id,
                'backup_configuration_id' => $legacy->backup_configuration_id,
                'cron_expression' => $legacy->cron_expression,
                'is_active' => $legacy->is_active,
                'notify_on_failure' => $legacy->notify_on_failure,
                'last_run_at' => $legacy->last_run_at,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
        }

        Schema::drop('redis_snapshot_schedules');

        Schema::table('backup_schedules', function (Blueprint $table): void {
            // The billing count in M3 is a DISTINCT over (target_type, target_id);
            // the due-schedule sweep runs every minute over is_active.
            $table->index(['target_type', 'target_id'], 'backup_schedules_target_index');
            $table->index('is_active', 'backup_schedules_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table): void {
            $table->dropIndex('backup_schedules_target_index');
            $table->dropIndex('backup_schedules_is_active_index');
        });

        Schema::create('redis_snapshot_schedules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->char('server_cache_service_id', 26);
            $table->char('backup_configuration_id', 26)->nullable();
            $table->string('cron_expression', 64);
            $table->boolean('is_active')->default(true);
            $table->boolean('notify_on_failure')->default(true);
            $table->char('server_cron_job_id', 26)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'is_active']);
            $table->unique('server_cache_service_id');
        });

        foreach (DB::table('backup_schedules')->where('target_type', 'cache')->orderBy('id')->cursor() as $row) {
            DB::table('redis_snapshot_schedules')->insert([
                'id' => $row->id,
                'server_id' => $row->server_id,
                'server_cache_service_id' => $row->target_id,
                'backup_configuration_id' => $row->backup_configuration_id,
                'cron_expression' => $row->cron_expression,
                'is_active' => $row->is_active,
                'notify_on_failure' => $row->notify_on_failure,
                'server_cron_job_id' => null,
                'last_run_at' => $row->last_run_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        DB::table('backup_schedules')->where('target_type', 'cache')->delete();

        Schema::table('backup_schedules', function (Blueprint $table): void {
            $table->char('server_cron_job_id', 26)->nullable();
            $table->char('server_id', 26)->nullable(false)->change();
        });

        Schema::rename('backup_schedules', 'server_backup_schedules');
    }
};
