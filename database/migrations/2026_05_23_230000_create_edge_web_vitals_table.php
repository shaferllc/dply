<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_web_vitals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('edge_deployment_id', 26)->nullable();
            $table->string('hostname', 255);
            $table->string('path', 2048);
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->decimal('cls', 8, 4)->nullable();
            $table->unsignedInteger('inp_ms')->nullable();
            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->string('country', 8)->nullable();
            $table->string('source', 32)->default('browser');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_web_vitals');
    }
};
