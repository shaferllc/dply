<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_cluster_id')->constrained('cloud_clusters')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cloud_app_id')->nullable()->constrained('cloud_apps')->nullOnDelete();
            $table->string('name');
            $table->string('engine');
            $table->string('version');
            $table->string('size');
            $table->string('do_database_id')->nullable();
            $table->longText('connection_details')->nullable();
            $table->integer('backup_retention_days')->default(7);
            $table->boolean('high_availability')->default(false);
            $table->string('status')->default('pending');
            $table->timestamp('provisioned_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['cloud_cluster_id', 'status']);
            $table->index(['cloud_app_id']);
            $table->index(['do_database_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_databases');
    }
};
