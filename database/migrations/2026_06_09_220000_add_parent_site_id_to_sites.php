<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A worker is a ROLE of its parent app, not an independent site: it inherits the
 * parent's entire environment (and resources), overriding only a small set
 * (APP_URL, HORIZON_*, the worker flag). `parent_site_id` links a derived worker
 * site to the app it mirrors, so there is a single env source and drift becomes
 * structurally impossible. Nullable: standalone sites have no parent.
 * See docs/DEPLOYMENT_METHODS.md and the worker-inheritance model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('parent_site_id')->nullable()->after('server_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('parent_site_id');
        });
    }
};
