<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first line of the queue's newest failure, so a "jobs are failing" alert
 * can say why — the first thing anyone opens the page to find out. Only set
 * when the queue has failures at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_queue_snapshots', function (Blueprint $table): void {
            $table->string('last_failure')->nullable()->after('failed_total');
        });
    }

    public function down(): void
    {
        Schema::table('site_queue_snapshots', function (Blueprint $table): void {
            $table->dropColumn('last_failure');
        });
    }
};
