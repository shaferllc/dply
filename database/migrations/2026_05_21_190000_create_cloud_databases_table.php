<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed database — a hosted Postgres / MySQL / Redis instance that
 * dply provisions on a cloud provider and attaches to Cloud sites.
 *
 * v1 backend is DigitalOcean Managed Databases (`backend_id` holds the
 * DO cluster id). The connection block (host/port/user/password) is
 * stored encrypted in the `connection` column once the cluster is
 * online; the engine-specific env-var map is derived from it on attach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_databases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index();
            $table->string('name');
            // postgres | mysql | redis
            $table->string('engine', 16);
            $table->string('version')->default('');
            // Portable size tier slug — small | medium | large.
            $table->string('size', 32)->default('small');
            $table->string('region')->default('');
            $table->string('backend')->default('digitalocean_managed_database');
            // Provider cluster id (DO database cluster id). Null until created.
            $table->string('backend_id')->nullable();
            $table->foreignUlid('provider_credential_id')->nullable();
            $table->string('status', 32)->default('provisioning');
            // Encrypted JSON connection block (host/port/user/password/database).
            $table->text('connection')->nullable();
            // Catch-all for non-secret provisioning detail + last error.
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_databases');
    }
};
