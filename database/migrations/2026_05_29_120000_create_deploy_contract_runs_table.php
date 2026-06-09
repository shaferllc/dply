<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_contract_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('preview_site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('preview_deployment_id', 26)->nullable();
            $table->string('git_commit', 64)->nullable();
            $table->foreignUlid('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            $table->json('checks');
            $table->json('summary');
            $table->text('waiver_reason')->nullable();
            $table->foreignUlid('waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['parent_site_id', 'preview_site_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_contract_runs');
    }
};
