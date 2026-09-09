<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue jobs that failed inside a serverless function.
 *
 * The app's own `failed_jobs` table lives in the customer's database, which
 * dply provisions but does not read — and on a function there is no worker
 * process or CLI to run `queue:failed` against. So the failure has to be
 * reported outward: the injected handler listens for Laravel's JobFailed
 * event during a queue slot and returns the details in the slot's JSON
 * report, and the pump's slot job writes them here.
 *
 * This is a mirror for operators to look at, not the source of truth. The
 * app's failed_jobs row is still what `queue:retry` acts on, which is why
 * `uuid` matters: it is the handle dply passes back to retry a specific job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serverless_failed_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();

            // Laravel's failed-job uuid — the handle `queue:retry {uuid}`
            // takes. Unique per site so a slot reporting the same failure
            // twice (a retried invocation, an overlapping slot) updates the
            // existing row instead of duplicating it.
            $table->string('uuid', 64)->nullable();

            $table->string('connection_name', 64)->nullable();
            $table->string('queue', 128)->nullable();
            $table->string('job_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->text('exception_excerpt')->nullable();

            // Set when an operator asks dply to retry this job, so the UI can
            // distinguish "still failed" from "sent back to the queue".
            $table->timestamp('retried_at')->nullable();

            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'uuid']);
            // The panel lists newest-first per site.
            $table->index(['site_id', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serverless_failed_jobs');
    }
};
