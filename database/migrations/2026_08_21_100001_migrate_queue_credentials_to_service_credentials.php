<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move every queue credential onto the shared `service_credentials` table.
 *
 * `namespace_id` + `scopes` collapse into one grant: `{"queue:<id>": [...]}`.
 * A null or empty `scopes` meant "both push and pop" on the old model, and an
 * empty scope list means "every scope on this resource" on the new one, so the
 * permissive case survives the move unchanged.
 *
 * Deliberately raw SQL. `secret` is an `encrypted` cast on both models, so
 * reading through Eloquent and writing back would decrypt and re-encrypt every
 * row — same plaintext, new ciphertext, and a needless pass of the app key
 * over every customer secret. Copying the column verbatim keeps the ciphertext
 * identical and decrypts the same on the far side.
 *
 * Ids are preserved, so anything holding a credential id keeps working.
 *
 * See docs/adr/dply-cache.md, decision 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dply_queue_credentials')) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO service_credentials (
                id, organization_id, name, token_prefix, token_hash, secret,
                grants, expires_at, revoked_at, last_used_at,
                created_by_user_id, created_at, updated_at
            )
            SELECT
                c.id,
                c.organization_id,
                c.name,
                c.token_prefix,
                c.token_hash,
                c.secret,
                jsonb_build_object(
                    'queue:' || c.namespace_id,
                    COALESCE(c.scopes::jsonb, '[]'::jsonb)
                ),
                c.expires_at,
                c.revoked_at,
                c.last_used_at,
                c.created_by_user_id,
                c.created_at,
                c.updated_at
            FROM dply_queue_credentials c
            ON CONFLICT (id) DO NOTHING
        SQL);

        Schema::drop('dply_queue_credentials');
    }

    public function down(): void
    {
        if (Schema::hasTable('dply_queue_credentials') || ! Schema::hasTable('service_credentials')) {
            return;
        }

        // Recreate the shape this migration consumed, then move the queue
        // grants back. Only credentials holding exactly one queue grant can
        // return — a key granted on a queue *and* a cache has no
        // representation in the old single-namespace schema, so it stays put
        // rather than being silently truncated to half of itself.
        Schema::create('dply_queue_credentials', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('namespace_id')->index();
            $table->foreignUlid('organization_id')->index();
            $table->string('name', 80);
            $table->string('token_prefix', 32)->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('secret')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['namespace_id', 'revoked_at']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO dply_queue_credentials (
                id, namespace_id, organization_id, name, token_prefix, token_hash,
                secret, scopes, expires_at, revoked_at, last_used_at,
                created_by_user_id, created_at, updated_at
            )
            SELECT
                s.id,
                substring(g.key from 7),
                s.organization_id,
                s.name,
                s.token_prefix,
                s.token_hash,
                s.secret,
                g.value,
                s.expires_at,
                s.revoked_at,
                s.last_used_at,
                s.created_by_user_id,
                s.created_at,
                s.updated_at
            FROM service_credentials s
            CROSS JOIN LATERAL jsonb_each(s.grants) AS g(key, value)
            WHERE g.key LIKE 'queue:%'
              AND jsonb_array_length(
                    (SELECT COALESCE(jsonb_agg(k), '[]'::jsonb)
                       FROM jsonb_object_keys(s.grants) AS k)
                  ) = 1
        SQL);

        // Remove exactly what was just copied back — by id, not by a grants
        // predicate. A LIKE over `grants` would also match the multi-service
        // keys the INSERT deliberately left behind, deleting credentials that
        // have no representation in the recreated table.
        DB::statement('DELETE FROM service_credentials WHERE id IN (SELECT id FROM dply_queue_credentials)');
    }
};
