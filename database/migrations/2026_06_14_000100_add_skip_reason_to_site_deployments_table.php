<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            // Machine-readable reason a deployment was skipped, so the UI can
            // render a distinct chip (e.g. "Blocked — billing") instead of a
            // neutral "skipped" that reads as a mysteriously stuck deploy. Null
            // for non-skipped deployments. See SiteDeployment::SKIP_REASON_*.
            $table->string('skip_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            $table->dropColumn('skip_reason');
        });
    }
};
