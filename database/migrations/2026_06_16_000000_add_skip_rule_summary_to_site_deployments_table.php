<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            // Human-readable summary of the deny window that blocked this deploy
            // (e.g. "Fri, Sat · 17:00–23:59"), captured at skip time so the
            // Deploys history can show WHICH rule held the deploy back even
            // after the policy is later edited. Null for non-skipped deploys
            // and skips not caused by a deploy window. See SKIP_REASON_DEPLOY_WINDOW.
            $table->string('skip_rule_summary')->nullable()->after('skip_reason');
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            $table->dropColumn('skip_rule_summary');
        });
    }
};
