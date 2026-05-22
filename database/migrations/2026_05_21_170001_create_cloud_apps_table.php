<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_cluster_id')->constrained('cloud_clusters')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('runtime');
            $table->string('framework')->nullable();
            $table->string('git_repository_url');
            $table->string('git_branch')->default('main');
            $table->string('git_commit_sha')->nullable();
            $table->integer('min_replicas')->default(1);
            $table->integer('max_replicas')->default(3);
            $table->decimal('cpu_limit', 3, 2)->default('0.50');
            $table->integer('memory_limit')->default(512); // MB
            $table->longText('env_vars')->nullable();
            $table->json('domains')->nullable();
            $table->string('ssl_status')->default('none');
            $table->timestamp('last_deploy_at')->nullable();
            $table->string('last_deploy_sha')->nullable();
            $table->string('container_image')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['cloud_cluster_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->unique(['cloud_cluster_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_apps');
    }
};
