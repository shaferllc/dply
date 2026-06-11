<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-key secret residency: the env vars a site keeps OUT of the loose
 * plaintext-in-DB `.env` blob. Each row is one escalated key. The loose
 * `env_file_content` keeps only a `${dply:secret:<id>}` placeholder for the
 * key so the editor/validator/drift logic still see the key exists — the
 * actual value lives here as either:
 *   - escrow mode   → `ciphertext` (an age blob; NOT APP_KEY-encrypted, that's
 *                     the point — see SiteSecretResidency), resolved at push, or
 *   - external mode → `store_id` + `reference` (a pointer; the value never
 *                     enters dply), resolved at push or on the box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_secret_residencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->char('site_id', 26);

            // The env var name this row owns (e.g. STRIPE_SECRET).
            $table->string('key');

            // escrow | external
            $table->string('mode');

            // escrow mode: the age-encrypted value. Stored as ciphertext we may
            // or may not be able to open (depends on the org key's holder) —
            // deliberately a plain text column, NOT an `encrypted` cast.
            $table->longText('ciphertext')->nullable();

            // external mode: pointer to the customer's own secret store + the
            // reference within it (e.g. "secret/data/stripe#key").
            $table->char('store_id', 26)->nullable();
            $table->string('reference')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'key']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_secret_residencies');
    }
};
