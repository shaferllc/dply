<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 7)->unique();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->date('published_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'slug']);
        });

        Schema::table('roadmap_items', function (Blueprint $table): void {
            $table->foreignUlid('target_release_id')
                ->nullable()
                ->after('target_quarter')
                ->constrained('roadmap_releases')
                ->nullOnDelete();
            $table->foreignUlid('shipped_release_id')
                ->nullable()
                ->after('target_release_id')
                ->constrained('roadmap_releases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipped_release_id');
            $table->dropConstrainedForeignId('target_release_id');
        });

        Schema::dropIfExists('roadmap_releases');
    }
};
