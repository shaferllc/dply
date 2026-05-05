<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server audit trail for the Caches workspace. Mirrors
 * `server_database_audit_events`. Recorded on install / uninstall /
 * restart / stop / start / flush so operators can see who did what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_cache_service_audit_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->string('event', 64);
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_cache_service_audit_events');
    }
};
