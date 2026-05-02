<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a JSON `phase_results` column to site_deployments to capture
 * the {@see DeployPhaseRunner}'s per-phase per-step results: ok flag,
 * captured stdout/stderr, duration_ms, skipped flag.
 *
 * Per the strategy memo: "Each phase a separate SiteDeployStep so the
 * UI shows per-phase status, timing, and logs." The runner produces
 * structured per-step output; this column persists it so the deploy
 * history UI can render a build/swap/release/restart timeline alongside
 * the existing log_output.
 *
 * Shape:
 *   {
 *     "build": [
 *       { "step_id": "01H…", "step_type": "composer_install",
 *         "command": "composer install --no-dev --optimize-autoloader",
 *         "ok": true, "output": "...", "duration_ms": 42180 },
 *       …
 *     ],
 *     "swap":    [{ … }],
 *     "release": [{ … }],
 *     "restart": [{ … }]
 *   }
 *
 * Nullable so existing deployments (and any deployment that doesn't
 * route through the new runner) read clean. The structured data is
 * additive — log_output stays the canonical streaming-output source
 * of truth, this column is the structured slice for filtering / UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table) {
            $table->json('phase_results')->nullable()->after('log_output');
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table) {
            $table->dropColumn('phase_results');
        });
    }
};
