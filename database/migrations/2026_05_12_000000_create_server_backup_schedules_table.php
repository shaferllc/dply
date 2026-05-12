<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backup_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            // 'database' targets a ServerDatabase row; 'site_files' targets a Site row.
            $table->string('target_type', 32);
            $table->ulid('target_id');
            // Optional — when null the backup runs to the default backup destination.
            $table->foreignUlid('backup_configuration_id')->nullable()->constrained('backup_configurations')->nullOnDelete();
            $table->string('cron_expression', 64);
            $table->boolean('is_active')->default(true);
            // Materialized cron entry; nullable so the schedule can exist before sync.
            $table->foreignUlid('server_cron_job_id')->nullable()->constrained('server_cron_jobs')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'is_active']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_backup_schedules');
    }
};
