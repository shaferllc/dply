<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_hooks', function (Blueprint $table) {
            $table->unsignedSmallInteger('timeout_seconds')->default(900)->after('script');
        });

        Schema::create('webhook_delivery_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('request_ip', 45)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 32);
            $table->string('detail', 512)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });

        Schema::create('integration_outbound_webhooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('name', 120);
            $table->string('driver', 24);
            $table->text('webhook_url');
            $table->json('events')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->json('allowed_ips')->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('allowed_ips');
        });

        Schema::dropIfExists('integration_outbound_webhooks');
        Schema::dropIfExists('webhook_delivery_logs');

        Schema::table('site_deploy_hooks', function (Blueprint $table) {
            $table->dropColumn('timeout_seconds');
        });
    }
};
