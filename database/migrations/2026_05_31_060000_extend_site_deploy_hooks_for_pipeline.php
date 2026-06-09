<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_hooks', function (Blueprint $table) {
            $table->foreignUlid('pipeline_id')
                ->nullable()
                ->after('site_id')
                ->constrained('site_deploy_pipelines')
                ->cascadeOnDelete();
            $table->string('hook_kind', 32)->default('shell')->after('phase');
            $table->string('anchor', 32)->default('after_clone')->after('hook_kind');
            $table->foreignUlid('anchor_step_id')
                ->nullable()
                ->after('anchor')
                ->constrained('site_deploy_steps')
                ->nullOnDelete();
            $table->string('label', 120)->nullable()->after('anchor_step_id');
            $table->string('notification_event', 64)->nullable()->after('label');
            $table->foreignUlid('notification_channel_id')
                ->nullable()
                ->after('notification_event')
                ->constrained('notification_channels')
                ->nullOnDelete();
            $table->text('webhook_url')->nullable()->after('notification_channel_id');
            $table->text('script')->nullable()->change();
            $table->index(['pipeline_id', 'anchor', 'sort_order']);
        });

        $this->backfillHookPipelines();
    }

    public function down(): void
    {
        Schema::table('site_deploy_hooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_id');
            $table->dropConstrainedForeignId('anchor_step_id');
            $table->dropConstrainedForeignId('notification_channel_id');
            $table->dropColumn(['hook_kind', 'anchor', 'label', 'notification_event', 'webhook_url']);
        });
    }

    private function backfillHookPipelines(): void
    {
        $sites = DB::table('sites')
            ->whereNotNull('active_deploy_pipeline_id')
            ->pluck('active_deploy_pipeline_id', 'id');

        foreach ($sites as $siteId => $pipelineId) {
            DB::table('site_deploy_hooks')
                ->where('site_id', $siteId)
                ->whereNull('pipeline_id')
                ->update(['pipeline_id' => $pipelineId]);
        }

        foreach (['before_clone', 'after_clone', 'after_activate'] as $phase) {
            DB::table('site_deploy_hooks')
                ->where('phase', $phase)
                ->update(['anchor' => $phase]);
        }
    }
};
