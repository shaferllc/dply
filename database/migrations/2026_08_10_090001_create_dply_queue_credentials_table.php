<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for a dply Queue namespace.
 *
 * Deliberately NOT `api_tokens`. That model hashes with bcrypt, which is
 * salted — so a cache key cannot be derived from the stored row, and a
 * revoked token could only be invalidated by waiting out a TTL. It also
 * writes `last_used_at` on every request, requires a `User`, and carries
 * org-wide abilities; a credential that ships inside every customer container
 * must outlive its creator and be able to do exactly one thing.
 *
 * So: sha256 + prefix, following `EdgeDeployHook`. A 48-character CSPRNG
 * secret has no brute-force surface, so a slow KDF buys no security while
 * costing the entire invalidation design. The hash is a plain unique index
 * lookup, and the cache key is derived from that same column — making
 * revocation an exact single-key eviction.
 *
 * Two credentials may be live per namespace at once. A `.env` only reaches a
 * running app on its next deploy, so single-secret rotation would guarantee
 * an outage window.
 *
 * See docs/adr/dply-queue.md, decision 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('namespace_id')->index();

            // Denormalized so metering and rate limiting never have to join
            // back through the namespace on the hot path.
            $table->foreignUlid('organization_id')->index();

            $table->string('name', 80);

            /**
             * The public access key id — what a client puts in
             * AWS_ACCESS_KEY_ID, and what the server resolves by. 20 chars to
             * match AWS's own format (`dplyq` + 15), with headroom in the
             * column. Safe to display in full: it is an identifier, not a
             * secret, and it is how an operator tells two live credentials
             * apart during a rotation.
             */
            $table->string('token_prefix', 32)->unique();

            // sha256 hex. Unique so resolution is one index probe with no
            // prefix scan and no per-candidate hashing.
            $table->char('token_hash', 64)->unique();

            // ['push'] | ['pop'] | ['push','pop'] — a push-only credential can
            // be handed to an app that must not drain, and vice versa.
            $table->json('scopes')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Coarse: written at most once per 60s per credential, gated on
            // the cached tuple. Never a write on the pop path. Drives the
            // "old credential still in use" hint during rotation.
            $table->timestamp('last_used_at')->nullable();

            $table->foreignUlid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['namespace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_credentials');
    }
};
