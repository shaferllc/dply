<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only inventory of servers discovered on the user's Ploi account. Each
 * row is the dply-side projection of a Ploi server, refreshed lazily on
 * inventory page load and forcibly before any migration begins. Rows whose
 * source disappears from Ploi are marked `removed_from_source` rather than
 * deleted, to preserve audit/migration history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ploi_servers', function (Blueprint $table): void {
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

            $table->unique(['provider_credential_id', 'source_id'], 'ploi_servers_credential_source_unq');
            $table->index(['provider_credential_id', 'removed_from_source'], 'ploi_servers_credential_removed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ploi_servers');
    }
};
