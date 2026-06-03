<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_deployment_schedules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('site_id', 26);
            $table->char('server_id', 26)->nullable();
            $table->string('cron_expression', 64);
            $table->string('timezone', 64)->nullable();
            $table->string('git_branch', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('notify_on_failure')->default(true);
            // Auto-pause bookkeeping so a perpetually-failing schedule stops
            // hammering the queue (mirrors the backup-schedule precedent).
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_active']);
            $table->index('is_active');

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_deployment_schedules');
    }
};
