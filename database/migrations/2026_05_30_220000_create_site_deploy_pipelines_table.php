<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_deploy_pipelines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'sort_order']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignUlid('active_deploy_pipeline_id')
                ->nullable()
                ->after('deploy_strategy')
                ->constrained('site_deploy_pipelines')
                ->nullOnDelete();
        });

        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->foreignUlid('pipeline_id')
                ->nullable()
                ->after('site_id')
                ->constrained('site_deploy_pipelines')
                ->cascadeOnDelete();
            $table->index(['pipeline_id', 'sort_order']);
        });

        $this->backfillPipelines();
    }

    public function down(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_deploy_pipeline_id');
        });

        Schema::dropIfExists('site_deploy_pipelines');
    }

    private function backfillPipelines(): void
    {
        $siteIds = DB::table('sites')->pluck('id');

        foreach ($siteIds as $siteId) {
            $pipelineId = (string) Str::ulid();
            $now = now();

            DB::table('site_deploy_pipelines')->insert([
                'id' => $pipelineId,
                'site_id' => $siteId,
                'name' => 'Default',
                'slug' => 'default',
                'description' => null,
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('site_deploy_steps')
                ->where('site_id', $siteId)
                ->update(['pipeline_id' => $pipelineId]);

            DB::table('sites')
                ->where('id', $siteId)
                ->update(['active_deploy_pipeline_id' => $pipelineId]);
        }
    }
};
