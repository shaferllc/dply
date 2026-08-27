<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-group FIFO, in the shape SQS message groups use.
 *
 * `SKIP LOCKED` buys throughput by letting a worker step over a row another
 * worker holds — which is exactly why the claim is not FIFO under concurrency
 * (docs/adr/dply-queue.md decision on ordering). Global FIFO would fix it by
 * capping the queue at one worker, which is the same as deleting the
 * autoscaler.
 *
 * A group key narrows the guarantee to where it is actually needed: jobs
 * sharing a key are handled one at a time, in order; different keys run fully
 * in parallel. "This customer's events in order" is the real requirement, never
 * "the entire queue in order".
 *
 * Nullable on purpose — an ungrouped job keeps today's unordered, maximally
 * concurrent behaviour, so this costs existing queues nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dply_queue_jobs', function (Blueprint $table): void {
            $table->string('group_key', 128)->nullable()->after('queue');

            // The claim asks "is any job in this group in flight" on every
            // reservation, so that lookup must not be a scan. Partial: only
            // grouped rows are ever probed this way.
            $table->index(['namespace_id', 'queue', 'group_key', 'visible_at'], 'dqj_group_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dply_queue_jobs', function (Blueprint $table): void {
            $table->dropIndex('dqj_group_claim_idx');
            $table->dropColumn('group_key');
        });
    }
};
