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
            // 'problem' (default — today's behavior, can page critical) vs 'suggestion'
            // (tuning recs that never page, render in their own section, ignore-with-cooldown).
            $table->string('kind', 16)->default('problem')->after('insight_key');
            $table->index(['server_id', 'kind', 'status'], 'insight_findings_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('insight_findings', function (Blueprint $table): void {
            $table->dropIndex('insight_findings_kind_status_idx');
            $table->dropColumn('kind');
        });
    }
};
