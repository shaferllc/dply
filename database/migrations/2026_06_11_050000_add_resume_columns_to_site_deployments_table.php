<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            // The release folder this deploy built into (timestamped dir or
            // blue/green slot). Persisted as soon as clone succeeds so a deploy
            // that fails before cutover still records which staged release a
            // "resume from phase" run should re-attach to.
            $table->string('release_folder')->nullable()->after('git_sha');

            // When this deploy is a resume of an earlier failed deploy, points
            // at that origin row — for the timeline lineage ("resumed from #…").
            $table->string('resume_of_deployment_id', 26)->nullable()->after('release_folder');
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table): void {
            $table->dropColumn(['release_folder', 'resume_of_deployment_id']);
        });
    }
};
