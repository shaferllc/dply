<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_deploys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_app_id')->constrained('cloud_apps')->cascadeOnDelete();
            $table->string('commit_sha');
            $table->string('git_branch');
            $table->string('git_author')->nullable();
            $table->text('git_message')->nullable();
            $table->string('status')->default('pending');
            $table->longText('build_output')->nullable();
            $table->string('container_image')->nullable();
            $table->string('container_image_tag')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['cloud_app_id', 'started_at']);
            $table->index(['cloud_app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploys');
    }
};
