<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('edge_backend')->nullable()->after('container_backend_id');
            $table->string('edge_backend_id')->nullable()->after('edge_backend');
        });

        Schema::create('edge_deployments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 32)->default('building');
            $table->string('git_commit', 64)->nullable();
            $table->string('git_branch')->nullable();
            $table->string('storage_prefix');
            $table->string('build_log_path')->nullable();
            $table->unsignedInteger('cf_kv_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_deployments');

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['edge_backend', 'edge_backend_id']);
        });
    }
};
