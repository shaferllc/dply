<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user-facing deployment method (a named cell in the placement × cutover
 * matrix; see docs/DEPLOYMENT_METHODS.md). Nullable: when unset, the effective
 * method is derived from `deploy_strategy` (atomic→Atomic, else Flat), so this
 * is purely additive and changes no existing deploy behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('deploy_method')->nullable()->after('deploy_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('deploy_method');
        });
    }
};
