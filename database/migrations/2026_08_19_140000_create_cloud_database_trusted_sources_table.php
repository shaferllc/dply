<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator IPs dply has added to a managed cluster's trusted-source list.
 *
 * This table is what makes the reaper safe. Provider APIs replace the whole rule
 * set and expose no notion of ownership, so without our own record the only way
 * to expire an entry would be to diff against the live list — which would also
 * strip rules a customer added by hand in the provider console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_database_trusted_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_database_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // The reaper's hot query: live entries past their expiry.
            $table->index(['expires_at', 'revoked_at']);
            // "Is my IP currently allowed on this cluster?"
            $table->index(['cloud_database_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_database_trusted_sources');
    }
};
