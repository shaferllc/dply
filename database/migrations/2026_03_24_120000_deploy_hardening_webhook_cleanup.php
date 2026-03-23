<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('webhook_allowed_ips')->nullable()->after('webhook_secret');
        });

        Schema::table('site_deployments', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable()->after('site_id');
            $table->index(['site_id', 'idempotency_key']);
        });

        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('timeout_seconds')->default(900)->after('custom_command');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_steps', function (Blueprint $table) {
            $table->dropColumn('timeout_seconds');
        });

        Schema::table('site_deployments', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('webhook_allowed_ips');
        });
    }
};
