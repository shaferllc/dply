<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origin marker for a managed Lookout project. NULL = a normal metered managed
 * project. 'bundle' = provisioned free as part of the bundled-products perk
 * (business-yearly/Enterprise) and therefore EXCLUDED from billing — the
 * OrganizationBillingStateComputer + LookoutProjectBillingObserver skip it, so
 * the org is never invoiced for the free project. See docs/adr/bundled-products-sso.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lookout_projects', function (Blueprint $table): void {
            $table->string('source')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lookout_projects', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
