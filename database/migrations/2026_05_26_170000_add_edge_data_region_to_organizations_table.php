<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Preferred R2/data region for Edge resources created on
            // behalf of this organization (P57). One of:
            //   default | eu | wnam | enam | weur | eeur | apac | oc
            // The first three CF jurisdictions ("default", "eu", "fedramp")
            // map to a header on bucket create; the geographic hub codes
            // map to `locationHint`. Persisted on Organization so future
            // edge bootstrap commands can read it without UI prompting.
            $table->string('edge_data_region', 16)->default('default')->after('firewall_settings');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('edge_data_region');
        });
    }
};
