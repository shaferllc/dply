<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks deploy steps / processes that are reconciled from a repo dply.yaml so
 * the per-deploy manifest sync can delete+recreate ONLY its own managed rows
 * (leaving user-authored rows untouched) and the UI can render them read-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table): void {
            $table->boolean('managed_by_manifest')->default(false)->after('phase');
        });

        Schema::table('site_processes', function (Blueprint $table): void {
            $table->boolean('managed_by_manifest')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table): void {
            $table->dropColumn('managed_by_manifest');
        });

        Schema::table('site_processes', function (Blueprint $table): void {
            $table->dropColumn('managed_by_manifest');
        });
    }
};
