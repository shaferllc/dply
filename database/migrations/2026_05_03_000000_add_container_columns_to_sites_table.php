<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Container image reference, e.g. "ghcr.io/acme/api:v1.2.3" or
            // "registry.digitalocean.com/acme/api:latest".
            $table->string('container_image', 500)->nullable();

            // Free-form registry hint (docker.io, ghcr.io, ecr.aws, etc.).
            // Used to look up the right credential for pulls. Optional —
            // the image string usually carries it, but operators sometimes
            // configure private registries with explicit pull secrets.
            $table->string('container_registry', 100)->nullable();

            // Listening port the container exposes inside its network.
            $table->unsignedSmallInteger('container_port')->nullable();

            // Backend provider that actually runs the container, e.g.
            // 'digitalocean_app_platform' or 'aws_app_runner'. Distinct
            // from the legacy `provider` column (which described the
            // VPS provider). For container sites, the server is a
            // virtual host of host_kind 'dply_edge' or similar, and
            // this column records which backend the dply edge layer
            // is using under the hood.
            $table->string('container_backend', 50)->nullable();

            // The remote app/service identifier returned by the
            // backend (DO App Platform app id, AWS App Runner ARN).
            $table->string('container_backend_id', 200)->nullable();

            // Region the container is deployed in (backend-specific).
            $table->string('container_region', 50)->nullable();
        });

        // Container sites don't have an on-disk document_root or
        // repository_path — relax NOT NULL on both so the same
        // sites table can model both VM-rooted and container-only
        // workloads.
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('document_root')->nullable()->change();
            $table->string('repository_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'container_image',
                'container_registry',
                'container_port',
                'container_backend',
                'container_backend_id',
                'container_region',
            ]);
        });
    }
};
