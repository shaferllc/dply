<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_deployment_ephemeral_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_deployment_id')->constrained('site_deployments')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_authorized_key_id')->nullable()->constrained('server_authorized_keys')->nullOnDelete();
            $table->string('public_key_fingerprint');
            $table->text('private_key_encrypted');
            $table->timestamp('provisioned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('site_deployment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_deployment_ephemeral_credentials');
    }
};
