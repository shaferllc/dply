<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of ploi_servers for Forge inventory. Same shape so the eventual b→c
 * promotion (single import_source_servers + source discriminator) is a flat
 * data migration. Until then both tables coexist; the inventory page reads
 * each independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_servers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_credential_id')->constrained('provider_credentials')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->string('name', 255);
            $table->string('ip_address', 45)->nullable();
            $table->string('provider_label', 64)->nullable();
            $table->string('server_type', 128)->nullable();
            $table->json('php_versions');
            $table->string('status', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('removed_from_source')->default(false);
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['provider_credential_id', 'source_id'], 'forge_servers_credential_source_unq');
            $table->index(['provider_credential_id', 'removed_from_source'], 'forge_servers_credential_removed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_servers');
    }
};
