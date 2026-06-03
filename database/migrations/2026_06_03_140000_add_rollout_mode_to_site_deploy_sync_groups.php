<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_sync_groups', function (Blueprint $table): void {
            // 'parallel' — fan out to all members at once (default, current behaviour).
            // 'sequential' — deploy members in sort order, one after another, halting on the first failure.
            $table->string('rollout_mode', 16)->default('parallel')->after('leader_site_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_sync_groups', function (Blueprint $table): void {
            $table->dropColumn('rollout_mode');
        });
    }
};
