<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `phase` column to site_deploy_steps so each step can be tagged
 * with one of the deploy pipeline's named phases.
 *
 * Per the multi-runtime strategy memo: "Named phases: build → swap →
 * release → restart. Each phase a separate SiteDeployStep so the UI
 * shows per-phase status, timing, and logs. Build/release have runtime-
 * aware defaults, user-editable in dashboard. Restart is dply-owned,
 * not user-editable (preserves atomic-release/FPM-reload correctness)."
 *
 * Backfill maps existing step types onto phases:
 *   - dependency installs (composer / npm) and asset builds → build
 *   - DB migrations + post-deploy cache priming → release
 *   - one-shot scaffolding (octane:install, reverb:install) → build
 *   - custom commands → build (sensible default; user can override)
 *
 * The SWAP and RESTART phases are dply-owned: SWAP flips the
 * `current` symlink between releases (atomic deploys); RESTART runs
 * `systemctl reload php-fpm` / `systemctl restart dply-site-{id}` for
 * non-PHP runtimes. No user-defined steps land in those phases —
 * they're emitted by the deploy pipeline itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->string('phase', 16)->default('build')->after('step_type');
            $table->index(['site_id', 'phase']);
        });

        // Backfill: map step_type → phase per the rules above.
        $buildTypes = [
            'composer_install',
            'npm_ci',
            'npm_install',
            'npm_run',
            'artisan_octane_install',
            'artisan_reverb_install',
            'artisan_config_cache',
            'artisan_route_cache',
            'artisan_view_cache',
            'custom',
        ];
        $releaseTypes = [
            'artisan_migrate',
            'artisan_optimize',
        ];

        DB::table('site_deploy_steps')
            ->whereIn('step_type', $buildTypes)
            ->update(['phase' => 'build']);

        DB::table('site_deploy_steps')
            ->whereIn('step_type', $releaseTypes)
            ->update(['phase' => 'release']);
    }

    public function down(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'phase']);
            $table->dropColumn('phase');
        });
    }
};
