<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_storage_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Org-scoped so anyone on the team can reuse the keys. Nullable for
            // personal (no-org) usage, mirroring provider_credentials.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Object-storage provider slug — matches config/object_storage.php
            // (digitalocean_spaces | hetzner | aws_s3 | custom_s3).
            $table->string('provider');
            $table->string('name');

            // S3 API key pair. The access key id is not secret (it's part of
            // signed URLs); the secret is encrypted at rest.
            $table->string('access_key_id');
            $table->text('secret_access_key');

            // Optional defaults that pre-fill the binding form when this
            // credential is chosen.
            $table->string('region')->nullable();
            $table->string('endpoint')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_storage_credentials');
    }
};
