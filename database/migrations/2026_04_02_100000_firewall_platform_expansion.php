<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('firewall_settings')->nullable()->after('cron_maintenance_note');
        });

        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->string('profile', 32)->nullable()->after('name');
            $table->json('tags')->nullable()->after('profile');
            $table->string('runbook_url', 2048)->nullable()->after('tags');
            $table->foreignUlid('site_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
        });

        Schema::create('server_firewall_apply_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('kind', 32)->default('apply');
            $table->boolean('success')->default(true);
            $table->string('rules_hash', 64)->nullable();
            $table->unsignedSmallInteger('rule_count')->default(0);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_firewall_apply_logs');

        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn(['profile', 'tags', 'runbook_url', 'site_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('firewall_settings');
        });
    }
};
