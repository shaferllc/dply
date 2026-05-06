<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            // Captured stdout/stderr buffer from the most recent install / uninstall / switch run.
            // The job writes throttled chunks while in flight so the workspace can stream progress
            // to the operator at poll cadence (~4s); the full buffer is overwritten on the next
            // run, so this is intentionally a single-slot field, not a history table.
            $table->longText('install_output')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            $table->dropColumn('install_output');
        });
    }
};
