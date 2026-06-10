<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-backend sites: a single Site served by ≥2 backends behind a balancer —
 * the prerequisite for rolling + canary deploys. Each row links the logical Site
 * to a backend app server; the code on a backend lives in a derived child Site
 * (parent_site_id) per the worker-pool replica pattern, pointed at by
 * backend_site_id (null for the primary's own server row). See
 * docs/MULTI_BACKEND_SITES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_backends', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The logical Site that owns the backend group.
            $table->foreignUlid('site_id')->index();
            // The app server this backend runs on.
            $table->foreignUlid('server_id')->index();
            // The derived child Site holding the code on that server (null for the
            // primary's own server row, where the code IS the logical Site).
            $table->foreignUlid('backend_site_id')->nullable();
            // primary | replica — exactly one primary per site (partial index below).
            $table->string('role', 16)->default('replica');
            // Weighted routing for canary (HAProxy substrate only).
            $table->unsignedSmallInteger('weight')->default(100);
            // provisioning | replaying | deploying | active | draining | errored
            // (mirrors WorkerPool member states for machinery reuse).
            $table->string('state', 32)->default('provisioning');
            // Set while a backend is pulled from rotation for a rolling step.
            $table->timestamp('drained_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // A server backs a given site at most once.
            $table->unique(['site_id', 'server_id']);
        });

        // Enforce exactly one primary backend per site at the DB level.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX site_backends_one_primary_per_site
                ON site_backends (site_id)
                WHERE role = 'primary'
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS site_backends_one_primary_per_site');
        }

        Schema::dropIfExists('site_backends');
    }
};
