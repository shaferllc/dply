<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index();
            $table->string('name');
            // The original worker the pool was created from (provenance).
            $table->foreignUlid('source_server_id')->nullable();
            // The single member that owns the scheduler / cron / migrations.
            $table->foreignUlid('primary_server_id')->nullable();
            // Desired member count (INCLUDING the primary). Reconciler converges to this.
            $table->unsignedInteger('desired_count')->default(1);
            // Hard cap for scale-ups (and any future autoscaler).
            $table->unsignedInteger('max_size')->default(10);
            // steady | scaling | degraded
            $table->string('status', 32)->default('steady');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->foreignUlid('worker_pool_id')->nullable()->after('workspace_id');
            // primary | replica (null when the server is not in a pool)
            $table->string('pool_role', 16)->nullable()->after('worker_pool_id');
            $table->index('worker_pool_id');
        });

        // Enforce exactly one primary per pool at the DB level (Postgres partial
        // unique index). Belt-and-suspenders for WorkerPoolManager's invariant.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX servers_one_primary_per_pool
                ON servers (worker_pool_id)
                WHERE pool_role = 'primary' AND worker_pool_id IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS servers_one_primary_per_pool');
        }

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropIndex(['worker_pool_id']);
            $table->dropColumn(['worker_pool_id', 'pool_role']);
        });

        Schema::dropIfExists('worker_pools');
    }
};
