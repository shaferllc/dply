<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database snapshots gain a lifecycle status so the Snapshots → Databases tab can
 * show a "pending" row the instant a snapshot is queued and update it in place as
 * the dump finishes (mirroring server images). Historically a Snapshot row was
 * only written on success, so existing rows default to 'completed'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('snapshots', 'status')) {
                // 'completed' default keeps every historical row (only ever
                // written on success) correct without a backfill.
                $table->string('status', 32)->default('completed')->after('reason');
            }
            if (! Schema::hasColumn('snapshots', 'error_message')) {
                $table->text('error_message')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('snapshots', 'error_message')) {
                $table->dropColumn('error_message');
            }
            if (Schema::hasColumn('snapshots', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
