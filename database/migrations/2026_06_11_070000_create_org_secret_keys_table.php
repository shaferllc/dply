<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization `age` keypair used to encrypt that org's escrowed secrets
 * (the {@see \App\Models\SiteSecretResidency} escrow mode). One key per org.
 *
 * The `public_recipient` is the age recipient string — safe to store in the
 * clear, it's only used to ENCRYPT. The matching private identity decrypts:
 *
 *   - identity_holder = "dply"     → `dply_identity` holds the private key,
 *     APP_KEY-encrypted. dply can decrypt this org's secrets — the value of this
 *     tier is BLAST-RADIUS isolation (a distinct key per org), not zero-knowledge.
 *   - identity_holder = "customer" → `dply_identity` is NULL. dply stores only
 *     ciphertext it cannot open; the customer supplies the identity at deploy
 *     (Tier 2b, a later PR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_secret_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // age recipient string (public — encrypt-only). Safe in the clear.
            $table->text('public_recipient');

            // dply | customer
            $table->string('identity_holder')->default('dply');

            // The private age identity, APP_KEY-encrypted. Present only for
            // dply-held keys; NULL when the customer holds the identity.
            $table->text('dply_identity')->nullable();

            // Short fingerprint of the recipient, for display / drift checks.
            $table->string('fingerprint')->nullable();

            $table->timestamps();

            // One key per org.
            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_secret_keys');
    }
};
