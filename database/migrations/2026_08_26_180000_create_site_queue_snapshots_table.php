<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-queue depth history for a site.
 *
 * One row per QUEUE per tick, not per site: "emails is backed up while default
 * is fine" is the question worth answering at 3am, and a site-level total hides
 * a stalled low-volume queue behind a healthy busy one.
 *
 * `source` records where the numbers came from — Horizon reads differently from
 * a plain artisan count, and a chart that silently mixes them is a chart that
 * lies at the moment a site installs Horizon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_queue_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('connection')->nullable();
            $table->string('queue');
            $table->string('source', 16)->default('artisan');

            $table->unsignedBigInteger('pending')->nullable();
            $table->unsignedBigInteger('reserved')->nullable();
            $table->unsignedBigInteger('failed_total')->nullable();
            // Age of the oldest waiting job. The number that separates "busy"
            // from "stuck" — depth alone cannot.
            $table->unsignedBigInteger('oldest_pending_age_s')->nullable();
            $table->unsignedInteger('worker_processes')->nullable();

            $table->timestamp('captured_at');
            $table->timestamps();

            // The read path is always "this site, this window, newest first".
            $table->index(['site_id', 'captured_at']);
            $table->index(['site_id', 'queue', 'captured_at']);
            // Retention prunes by age across every site.
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_queue_snapshots');
    }
};
