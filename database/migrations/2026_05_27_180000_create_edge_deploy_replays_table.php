<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_deploy_replays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('preview_site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('preview_deployment_id', 26)->nullable();
            $table->foreignUlid('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('sample_limit')->default(20);
            $table->unsignedSmallInteger('window_minutes')->default(60);
            $table->json('samples')->nullable();
            $table->json('results')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['parent_site_id', 'preview_site_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_deploy_replays');
    }
};
