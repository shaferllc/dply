<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_findings', function (Blueprint $table): void {
            // Suggestion lifecycle: user clicks "Ignore" on a recommendation, finding moves
            // to status='ignored'. Recorder honors a per-insight cooldown so the same suggestion
            // doesn't reopen the next scheduled run — gives the user a real "I considered this
            // and chose not to act" outcome instead of perpetual reappearance.
            $table->timestampTz('ignored_at')->nullable()->after('acknowledged_by_user_id');
            $table->char('ignored_by_user_id', 26)->nullable()->after('ignored_at');
        });
    }

    public function down(): void
    {
        Schema::table('insight_findings', function (Blueprint $table): void {
            $table->dropColumn(['ignored_at', 'ignored_by_user_id']);
        });
    }
};
