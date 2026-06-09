<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['artisan_migrate', 'artisan_optimize'] as $stepType) {
            DB::table('site_deploy_steps')
                ->where('step_type', $stepType)
                ->where(function ($query) {
                    $query->whereNull('phase')
                        ->orWhere('phase', 'build');
                })
                ->update(['phase' => 'release']);
        }
    }

    public function down(): void
    {
        // Non-reversible without snapshotting prior phase values.
    }
};
