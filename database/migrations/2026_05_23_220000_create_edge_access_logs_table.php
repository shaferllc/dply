<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_access_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('edge_deployment_id', 26)->nullable();
            $table->string('hostname', 255);
            $table->string('method', 12)->default('GET');
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('bytes_egress')->default(0);
            $table->string('country', 8)->nullable();
            $table->string('cache_status', 32)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('source', 32)->default('worker');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
        });

        Schema::create('edge_performance_hourly', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hour_start');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_egress')->default(0);
            $table->unsignedInteger('duration_ms_total')->default(0);
            $table->unsignedInteger('duration_ms_p95')->nullable();
            $table->unsignedSmallInteger('status_2xx')->default(0);
            $table->unsignedSmallInteger('status_4xx')->default(0);
            $table->unsignedSmallInteger('status_5xx')->default(0);
            $table->unsignedSmallInteger('cache_hits')->default(0);
            $table->string('source', 32)->default('worker');
            $table->timestamps();

            $table->unique(['site_id', 'hour_start', 'source']);
            $table->index(['organization_id', 'hour_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_performance_hourly');
        Schema::dropIfExists('edge_access_logs');
    }
};
