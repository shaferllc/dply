<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('kind', 32)->default('byo_site');
            $table->timestamps();

            $table->unique('slug');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects');
        });

        $now = now();
        foreach (DB::table('sites')->orderBy('id')->cursor() as $site) {
            $slug = $site->slug.'-'.$site->id;
            $projectId = DB::table('projects')->insertGetId([
                'organization_id' => $site->organization_id,
                'user_id' => $site->user_id,
                'name' => $site->name,
                'slug' => $slug,
                'kind' => 'byo_site',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('sites')->where('id', $site->id)->update(['project_id' => $projectId]);
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->unique('project_id');
        });

        Schema::table('site_deployments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('site_id')->constrained('projects');
        });

        DB::statement('UPDATE site_deployments SET project_id = (SELECT project_id FROM sites WHERE sites.id = site_deployments.site_id)');

        Schema::table('site_deployments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });

        Schema::dropIfExists('projects');
    }
};
