<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_clusters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_credential_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('region');
            $table->string('tier')->default('starter');
            $table->string('do_kubernetes_cluster_id')->nullable();
            $table->longText('kubeconfig')->nullable();
            $table->json('node_pool_spec');
            $table->string('status')->default('pending');
            $table->timestamp('provisioned_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['do_kubernetes_cluster_id']);
            $table->unique(['organization_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_clusters');
    }
};
