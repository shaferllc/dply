<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which rendered-config version (see
     * VectorLogAggregatorInstallScripts::CONFIG_VERSION) the box was last installed
     * with, so the platform can detect a stale aggregator and prompt a re-sync.
     * Nullable: existing rows predate versioning and read as "unknown" (stale).
     */
    public function up(): void
    {
        Schema::table('server_log_aggregators', function (Blueprint $table): void {
            $table->unsignedInteger('config_version')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('server_log_aggregators', function (Blueprint $table): void {
            $table->dropColumn('config_version');
        });
    }
};
