<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the queue credential's secret reversibly, alongside its hash.
 *
 * The original design hashed and never kept the plaintext. That works for a
 * bearer token — present it, hash it, compare. It cannot work for SigV4,
 * which is what the SQS-compatible surface requires: the client signs the
 * canonical request with the secret, and the server must recompute the same
 * HMAC to verify. There is nothing to compare a hash against.
 *
 * So the secret is encrypted at rest (Laravel's `encrypted` cast, keyed by
 * APP_KEY) rather than hashed — exactly the tradeoff `RealtimeApp::app_secret`
 * already makes, and for exactly the same reason: Pusher's auth is also an
 * HMAC over a shared secret.
 *
 * `token_hash` is deliberately kept. It stays the lookup key and the cache-key
 * source, so the revocation property the credential design was built around —
 * an exact single-key `Cache::forget`, no side index, no plaintext — is
 * unchanged. It also remains the comparison for the native bearer-token
 * driver, which does not need the plaintext.
 *
 * Honest about the cost: encrypted-at-rest is weaker than hashed. A database
 * dump alone no longer leaks nothing; it leaks nothing only while APP_KEY is
 * safe. That is inherent to any shared-secret signing scheme, and it is why
 * these credentials are scoped to one namespace and one product rather than
 * carrying org-wide abilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dply_queue_credentials', function (Blueprint $table): void {
            // Encrypted ciphertext is much larger than the 48-char plaintext.
            $table->text('secret')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('dply_queue_credentials', function (Blueprint $table): void {
            $table->dropColumn('secret');
        });
    }
};
