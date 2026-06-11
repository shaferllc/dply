<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move queue:restart / horizon:terminate steps out of the RELEASE phase (where
 * they ran before the atomic cutover and bounced workers onto the OLD release)
 * into the post-cutover RESTART phase, so they reload workers onto the new code.
 * See SiteDeployStep::RESTART_STEP_TYPES.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_deploy_steps')
            ->whereIn('step_type', ['artisan_queue_restart', 'artisan_horizon_terminate'])
            ->where(function ($query): void {
                $query->where('phase', 'release')
                    ->orWhere('phase', 'build')
                    ->orWhereNull('phase');
            })
            ->update(['phase' => 'restart']);
    }

    public function down(): void
    {
        // Reverse to 'release' (the prior default) for these step types.
        DB::table('site_deploy_steps')
            ->whereIn('step_type', ['artisan_queue_restart', 'artisan_horizon_terminate'])
            ->where('phase', 'restart')
            ->update(['phase' => 'release']);
    }
};
