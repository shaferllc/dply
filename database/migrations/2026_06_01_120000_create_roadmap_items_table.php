<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('planned');
            $table->string('area', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->date('shipped_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
            $table->index(['is_published', 'status']);
            $table->index('area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_items');
    }
};
