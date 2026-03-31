<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('cron_maintenance_until')->nullable();
            $table->string('cron_maintenance_note', 500)->nullable();
        });

        Schema::create('organization_cron_job_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('cron_expression', 64);
            $table->text('command');
            $table->string('user')->default('root');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('server_cron_job_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_cron_job_id')->constrained('server_cron_jobs')->cascadeOnDelete();
            $table->string('run_ulid', 32)->index();
            $table->string('trigger', 16)->default('manual');
            $table->string('status', 16)->default('running');
            $table->unsignedSmallInteger('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_cron_job_id', 'started_at']);
        });

        Schema::table('server_cron_jobs', function (Blueprint $table) {
            $table->string('schedule_timezone', 64)->nullable()->after('last_run_output');
            $table->string('overlap_policy', 24)->default('allow')->after('schedule_timezone');
            $table->boolean('alert_on_failure')->default(false)->after('overlap_policy');
            $table->boolean('alert_on_pattern_match')->default(false)->after('alert_on_failure');
            $table->string('alert_pattern', 512)->nullable()->after('alert_on_pattern_match');
            $table->text('env_prefix')->nullable()->after('alert_pattern');
            $table->foreignUlid('depends_on_job_id')->nullable()->after('env_prefix')->constrained('server_cron_jobs')->nullOnDelete();
            $table->string('maintenance_tag', 64)->nullable()->after('depends_on_job_id');
            $table->foreignUlid('applied_template_id')->nullable()->after('maintenance_tag')->constrained('organization_cron_job_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_template_id');
            $table->dropConstrainedForeignId('depends_on_job_id');
            $table->dropColumn([
                'schedule_timezone',
                'overlap_policy',
                'alert_on_failure',
                'alert_on_pattern_match',
                'alert_pattern',
                'env_prefix',
                'maintenance_tag',
            ]);
        });

        Schema::dropIfExists('server_cron_job_runs');

        Schema::dropIfExists('organization_cron_job_templates');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['cron_maintenance_until', 'cron_maintenance_note']);
        });
    }
};
