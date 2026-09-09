<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a job ran, so one history covers both.
 *
 * A worker pool runs the SAME application for the same site, so its job events
 * belong in that site's history rather than in a parallel one — "which of my
 * jobs ran and how long did they take" is one question, and answering it in two
 * places means neither answer is complete.
 *
 * `source` distinguishes them without splitting them: 'agent' for the in-app
 * package on the site's own box, 'pool' for a managed worker server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_queue_job_runs', function (Blueprint $table): void {
            $table->string('source', 16)->default('agent')->after('status');
            $table->ulid('worker_pool_id')->nullable()->after('source');
            $table->index(['site_id', 'source', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::table('site_queue_job_runs', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'source', 'ran_at']);
            $table->dropColumn(['source', 'worker_pool_id']);
        });
    }
};
