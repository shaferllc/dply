<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serverless_function_deployments', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->after('trigger');
            $table->index(['idempotency_key', 'serverless_project_id', 'trigger'], 'serverless_deployments_idempotency_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('serverless_function_deployments', function (Blueprint $table) {
            $table->dropIndex('serverless_deployments_idempotency_lookup');
            $table->dropColumn('idempotency_key');
        });
    }
};
