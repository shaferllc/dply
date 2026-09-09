<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two metrics managed queues bill on, alongside the jobs-pushed count the
 * dashboard already keeps (docs/adr/managed-queue-workers.md, decision 6).
 *
 * Worker time is stored as **MiB-seconds**, not seconds. Price varies with
 * the worker's memory size, so a bare second cannot price a fleet whose sizes
 * differ — and every fleet's sizes differ the moment someone resizes one.
 * MiB-seconds multiply straight through by a per-MiB rate, and split by class
 * because Pro carries a premium over the equivalent Flex size.
 *
 * `billed_through_at` on the worker row is what lets a long-running Pro worker
 * bill before it stops: accrual is [started_at | billed_through_at, now], so
 * an always-on worker is invoiced hourly rather than never.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dply_queue_usage_daily', function (Blueprint $table): void {
            // Job lifecycle events: dispatch, start, delete, visibility
            // extension — payload-bearing ones counted in 64 KiB chunks.
            $table->unsignedBigInteger('operations')->default(0)->after('jobs_pushed');
            $table->unsignedBigInteger('flex_mib_seconds')->default(0)->after('operations');
            $table->unsignedBigInteger('pro_mib_seconds')->default(0)->after('flex_mib_seconds');
        });

        Schema::table('dply_queue_workers', function (Blueprint $table): void {
            $table->timestamp('billed_through_at')->nullable()->after('billed_at');
        });
    }

    public function down(): void
    {
        Schema::table('dply_queue_usage_daily', function (Blueprint $table): void {
            $table->dropColumn(['operations', 'flex_mib_seconds', 'pro_mib_seconds']);
        });

        Schema::table('dply_queue_workers', function (Blueprint $table): void {
            $table->dropColumn('billed_through_at');
        });
    }
};
