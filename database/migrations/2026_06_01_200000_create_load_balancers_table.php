<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_balancers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_id')->nullable();
            $table->string('name');
            $table->string('provider', 32)->default('hetzner');
            $table->string('region', 64)->nullable();
            $table->string('load_balancer_type', 32)->default('lb11');
            $table->string('algorithm', 32)->default('round_robin');
            $table->string('status', 32)->default('provisioning');
            $table->string('public_ipv4', 45)->nullable();
            $table->string('public_ipv6', 64)->nullable();
            $table->string('private_ip', 45)->nullable();
            $table->string('hetzner_network_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('load_balancer_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('load_balancer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('provider_server_id')->nullable();
            $table->string('status', 32)->default('healthy');
            $table->timestamps();

            $table->unique(['load_balancer_id', 'server_id']);
        });

        Schema::create('load_balancer_services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('load_balancer_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 16)->default('http');
            $table->unsignedSmallInteger('listen_port');
            $table->unsignedSmallInteger('destination_port');
            $table->boolean('sticky_sessions')->default(false);
            $table->boolean('health_check_enabled')->default(true);
            $table->string('health_check_protocol', 16)->default('http');
            $table->unsignedSmallInteger('health_check_port');
            $table->string('health_check_path', 255)->default('/');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_balancer_services');
        Schema::dropIfExists('load_balancer_targets');
        Schema::dropIfExists('load_balancers');
    }
};
