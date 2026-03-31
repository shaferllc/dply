<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_project_id')->constrained('edge_projects')->cascadeOnDelete();
            $table->string('application_name', 255);
            $table->string('framework', 32);
            $table->string('git_ref', 255);
            $table->string('status', 32)->default('queued');
            $table->string('trigger', 32);
            $table->string('idempotency_key', 255)->nullable();
            $table->text('provisioner_output')->nullable();
            $table->string('revision_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['edge_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_deployments');
    }
};
