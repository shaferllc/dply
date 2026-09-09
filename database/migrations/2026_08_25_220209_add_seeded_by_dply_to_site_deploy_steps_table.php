<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record whether dply created a deploy step, instead of inferring it.
 *
 * SiteDeployStepsRuntimeReconciler decided "this step was auto-seeded" by
 * matching its type+command against the set the defaults service can emit. That
 * inference breaks the moment an emitted command changes: the previously-seeded
 * step stops matching, is reclassified as hand-written, and is protected from
 * updates forever. It happened immediately — wrapping the migrate command in a
 * corepack fallback orphaned every step seeded before it, leaving sites running
 * a command that exits 127.
 *
 * Provenance is a fact worth storing, not guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->boolean('seeded_by_dply')->default(false)->after('managed_by_manifest');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->dropColumn('seeded_by_dply');
        });
    }
};
