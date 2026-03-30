<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('provider')->default('digitalocean'); // digitalocean, custom
            $table->string('provider_id')->nullable(); // droplet id, etc.
            $table->string('ip_address')->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_private_key')->nullable(); // encrypted key for this server
            $table->string('status')->default('pending'); // pending, provisioning, ready, error, disconnected
            $table->string('region')->nullable();
            $table->string('size')->nullable();
            $table->json('meta')->nullable(); // provider-specific (image, vpc, etc.)
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
