<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Async invocations and the full activation record behind them.
 *
 * Until now every row was written once, after a blocking invoke that already
 * had the outcome in hand. An async invoke returns only an activation id —
 * the outcome arrives later, when the poller fetches the activation record —
 * so a row now has a lifecycle (`state`) and somewhere to keep the record it
 * was built from.
 *
 * `wait_time` / `init_time` are pulled out of that record because they are
 * the two numbers that explain a slow invocation: queueing before the
 * container ran, and cold-start initialisation inside it.
 *
 * @see https://docs.digitalocean.com/products/functions/reference/activation-records/
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            // pending | completed | failed. Existing rows are all outcomes
            // that already landed, so they default to completed.
            $table->string('state', 12)->default('completed')->after('source');
            $table->unsignedInteger('wait_time_ms')->nullable()->after('duration_ms');
            $table->unsignedInteger('init_time_ms')->nullable()->after('wait_time_ms');
            // The activation record as returned, minus its logs (those stay
            // in log_lines) — kept whole so the UI can show what the platform
            // actually reported rather than dply's summary of it.
            $table->json('activation')->nullable()->after('log_lines');

            // The poller looks rows up by activation id; the Logs page counts
            // in-flight invocations per site.
            $table->index('activation_id');
            $table->index(['site_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'state']);
            $table->dropIndex(['activation_id']);
            $table->dropColumn(['state', 'wait_time_ms', 'init_time_ms', 'activation']);
        });
    }
};
