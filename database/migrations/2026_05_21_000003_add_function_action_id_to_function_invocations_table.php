<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each recorded invocation an action dimension.
 *
 * Logs are per-action once a Site holds N actions, so `function_invocations`
 * gains a nullable `function_action_id`. It stays nullable: an organic web
 * invocation can be ingested before its action row is known, and historic
 * rows are backfilled to the Site's single action by the
 * `serverless:backfill-function-actions` command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            $table->foreignUlid('function_action_id')->nullable()->after('site_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            $table->dropIndex(['function_action_id']);
            $table->dropColumn('function_action_id');
        });
    }
};
