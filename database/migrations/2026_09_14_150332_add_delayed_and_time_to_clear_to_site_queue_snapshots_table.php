<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two readings the sweep can now take on every site.
 *
 * `delayed` is jobs scheduled for later — not a backlog, so it must not sit in
 * `pending`. `time_to_clear_s` is Horizon's estimate of how long the backlog
 * takes to drain; it used to be stored in `oldest_pending_age_s`, which now
 * holds the real age of the oldest waiting job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_queue_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('delayed')->nullable()->after('pending');
            $table->unsignedBigInteger('time_to_clear_s')->nullable()->after('oldest_pending_age_s');
        });
    }

    public function down(): void
    {
        Schema::table('site_queue_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['delayed', 'time_to_clear_s']);
        });
    }
};
