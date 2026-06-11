<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit site ↔ worker-pool attachments. Historically a pool was "attached" to
 * a site only IMPLICITLY (WorkerPool.source_server_id == site.server_id), which
 * meant operators couldn't choose which workers serve a site and a pool on a
 * different box was invisible. A row here makes the attachment explicit; when a
 * site has any explicit rows they fully define its attached set (see
 * Site::attachedWorkerPools()), otherwise the implicit match still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_worker_pool', function (Blueprint $table): void {
            // Pure pivot — no surrogate key (belongsToMany inserts don't populate
            // one); the (site_id, worker_pool_id) pair is the logical key.
            $table->char('site_id', 26);
            $table->char('worker_pool_id', 26);

            $table->timestamps();

            $table->primary(['site_id', 'worker_pool_id']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('worker_pool_id')->references('id')->on('worker_pools')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_worker_pool');
    }
};
