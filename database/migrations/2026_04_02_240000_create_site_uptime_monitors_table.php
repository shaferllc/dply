<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_uptime_monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('path', 2048)->nullable();
            $table->string('probe_region', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_ok')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_uptime_monitors');
    }
};
