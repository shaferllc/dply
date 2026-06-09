<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_preview_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Guest reviewer label when there's no logged-in user (magic-link path).
            $table->string('author_label', 120)->nullable();
            $table->string('author_email', 255)->nullable();
            // CSS selector for the element the comment is anchored to;
            // null for "page-level" comments.
            $table->string('selector', 500)->nullable();
            $table->unsignedInteger('viewport_width')->nullable();
            $table->string('url_path', 2048);
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_preview_comments');
    }
};
