<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Object-storage buckets attached to Cloud sites — DO Spaces in v1, AWS S3
 * and Cloudflare R2 to follow. Mirrors the cloud_databases shape so the
 * same provisioning / attach lifecycle ideas (status enum, encrypted
 * connection blob, multi-site via pivot) carry over.
 *
 * This PR only creates pending records when the user adds a bucket in the
 * form — actual provider provisioning is a separate follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_buckets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index();
            $table->string('name', 80);
            $table->string('backend', 60)->default('digitalocean_spaces');
            $table->string('backend_id', 200)->nullable();
            $table->string('region', 60)->nullable();
            $table->foreignUlid('provider_credential_id')->nullable()->index();
            $table->string('status', 30)->default('pending');
            // Encrypted, like CloudDatabase.connection. Holds the bucket
            // name on the provider plus the access-key pair we generate
            // for this bucket once provisioning runs.
            $table->text('connection')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_buckets');
    }
};
