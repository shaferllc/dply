<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One credential shape for every AWS-compatible managed service.
 *
 * Supersedes `dply_queue_credentials`. The move is forced by the framework,
 * not chosen: `config/cache.php`'s `dynamodb` store and `config/queue.php`'s
 * `sqs` store both read AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY, so an app
 * using dply Queue *and* dply Cache cannot hold two key pairs without editing
 * a config file it owns. A credential therefore cannot be resource-scoped.
 * See docs/adr/dply-cache.md, decision 6.
 *
 * Everything that made the queue credential work is preserved deliberately:
 * sha256 rather than bcrypt (a 48-character CSPRNG secret has no brute-force
 * surface, and a salted hash would make the cache key underivable, degrading
 * revocation to waiting out a TTL); the prefix doubling as the public access
 * key id; and `last_used_at` written at most once a minute so the pop path
 * never writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Owner. Denormalized onto the credential so metering and rate
            // limiting never join back through a resource on the hot path.
            $table->foreignUlid('organization_id')->index();

            $table->string('name', 80);

            /**
             * The public access key id — what a client puts in
             * AWS_ACCESS_KEY_ID and what the server resolves by. 20 chars to
             * match AWS's format (`dply` + 16), with headroom in the column.
             * Safe to display in full: an identifier, not a secret, and it is
             * how an operator tells two live credentials apart mid-rotation.
             */
            $table->string('token_prefix', 32)->unique();

            // sha256 hex. Unique so resolution is one index probe with no
            // prefix scan and no per-candidate hashing.
            $table->char('token_hash', 64)->unique();

            /**
             * The shared secret, ENCRYPTED and not hashed. SigV4 is an HMAC
             * over the secret, so the server must be able to recompute it —
             * the hash above cannot serve here. Same tradeoff as
             * `RealtimeApp::app_secret`, for the same reason. `token_hash`
             * remains for lookup, cache-key derivation, and the bearer path.
             */
            $table->text('secret')->nullable();

            /**
             * What this key may do, as `{"<service>:<resource_id>": [scopes]}`
             * — e.g. `{"queue:01J…": ["push","pop"], "cache:01K…": ["read"]}`.
             *
             * A flat map rather than a list of objects so both directions are
             * one operation: the hot path reads scopes by key, and the control
             * plane finds every credential for a resource with a jsonb key
             * test. jsonb (not json) so that test can use the GIN index below.
             */
            $table->jsonb('grants')->default('{}');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Coarse: at most one write per 60s per credential, gated on the
            // cached tuple. Drives the "old credential still in use" hint
            // during a rotation; not an audit trail.
            $table->timestamp('last_used_at')->nullable();

            $table->foreignUlid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
        });

        // `grants ? 'queue:01J…'` — the key-existence test the control plane
        // uses to list a resource's credentials. Deliberately the DEFAULT
        // jsonb_ops opclass, not jsonb_path_ops: the latter is smaller and
        // faster but indexes only `@>`, so it would silently not serve the `?`
        // this schema is designed around.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE INDEX service_credentials_grants_gin ON service_credentials USING gin (grants)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_credentials');
    }
};
