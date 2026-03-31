<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rule_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->json('rules');
            $table->timestamps();

            $table->index(['organization_id', 'server_id']);
        });

        Schema::create('server_firewall_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 200)->nullable();
            $table->json('rules');
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::create('server_firewall_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('event', 64);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
            $table->string('protocol', 16)->default('tcp')->change();
        });
    }

    public function down(): void
    {
        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable(false)->change();
            $table->string('protocol', 8)->default('tcp')->change();
        });

        Schema::dropIfExists('server_firewall_audit_events');
        Schema::dropIfExists('server_firewall_snapshots');
        Schema::dropIfExists('firewall_rule_templates');
    }
};
