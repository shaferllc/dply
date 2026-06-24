<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookout_projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // The site whose error tracking this project backs (nullable so a
            // project survives a site delete until explicitly torn down).
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            // The error_tracking SiteBinding this project is wired to.
            $table->foreignUlid('site_binding_id')->nullable()->constrained('site_bindings')->nullOnDelete();

            // The project's id on the Lookout (uselookout.app) instance.
            $table->string('lookout_project_id')->nullable();
            $table->string('name');

            // Billing tier (config('lookout.tiers')) + lifecycle status.
            $table->string('tier')->default('starter');
            $table->string('status')->default('provisioning')->index();
            $table->unsignedInteger('retention_days')->nullable();

            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookout_projects');
    }
};
