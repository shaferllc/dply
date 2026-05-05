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
            // Org-level acknowledgement: one click silences a critical
            // finding from the Insights banner for everyone in the org.
            // The finding stays in the list below — ack only affects the
            // surfaced banner. On reopen (resolved → open) the recorder
            // clears these so a recurring issue resurfaces.
            $table->timestampTz('acknowledged_at')->nullable()->after('resolved_at');
            $table->char('acknowledged_by_user_id', 26)->nullable()->after('acknowledged_at');

            $table->index(['server_id', 'status', 'severity', 'acknowledged_at'], 'insight_findings_banner_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('insight_findings', function (Blueprint $table): void {
            $table->dropIndex('insight_findings_banner_lookup_idx');
            $table->dropColumn(['acknowledged_at', 'acknowledged_by_user_id']);
        });
    }
};
