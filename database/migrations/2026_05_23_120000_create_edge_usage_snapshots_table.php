<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_usage_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_egress')->default(0);
            $table->unsignedBigInteger('r2_storage_bytes')->default(0);
            $table->unsignedInteger('r2_class_a_ops')->default(0);
            $table->unsignedInteger('r2_class_b_ops')->default(0);
            $table->string('source', 32)->default('placeholder');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'period_start', 'source']);
            $table->index(['organization_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_usage_snapshots');
    }
};
