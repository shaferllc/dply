<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off delayed deploys: a deploy queued to run at a future moment, evaluated
 * by the control-plane RunDueScheduledDeploysCommand tick. Distinct from the
 * recurring SiteDeploymentSchedule (cron) — this is single-shot and cancelable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_deploys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('site_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->timestamp('run_at')->index();
            $table->string('status', 16)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'run_at']);
            $table->index(['site_id', 'status']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_deploys');
    }
};
