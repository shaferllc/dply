<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_ssh_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('server_authorized_key_id')->nullable()->constrained('server_authorized_keys')->nullOnDelete();
            $table->string('name');
            $table->string('public_key_fingerprint');
            $table->string('target_linux_user')->default('');
            $table->timestamp('expires_at');
            $table->timestamp('provisioned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_ssh_sessions');
    }
};
