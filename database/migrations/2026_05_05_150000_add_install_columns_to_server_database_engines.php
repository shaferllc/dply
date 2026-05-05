<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add lifecycle columns to `server_database_engines` so the workspace can install / uninstall /
 * adjust engines on demand. Existing rows (created at provision time) are backfilled to
 * `running` so the new flow doesn't claim every legacy server has a broken engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_database_engines', function (Blueprint $table) {
            $table->string('status', 32)->default('running')->after('is_default'); // pending|installing|running|stopped|failed|uninstalling
            $table->unsignedSmallInteger('port')->default(3306)->after('status');
            $table->text('error_message')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('server_database_engines', function (Blueprint $table) {
            $table->dropColumn(['status', 'port', 'error_message']);
        });
    }
};
