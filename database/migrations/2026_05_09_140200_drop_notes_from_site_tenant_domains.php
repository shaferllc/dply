<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the deprecated `notes` column from `site_tenant_domains` now
 * that the previous migration backfilled it into the unified `comment`
 * field. Routing items use `comment` exclusively across all 4 tables.
 *
 * down() recreates the column shape but does not restore data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_tenant_domains', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        Schema::table('site_tenant_domains', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('label');
        });
    }
};
