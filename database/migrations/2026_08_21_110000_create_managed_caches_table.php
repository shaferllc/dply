<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed cache — the tenancy unit of dply Cache.
 *
 * One model, two tiers (docs/adr/dply-cache.md, decision 3). `shared` is the
 * free Postgres-backed store this table's sibling `dply_cache_items` holds;
 * `dedicated` owns nothing itself and points at a `cloud_databases` row, so
 * every backend, provisioning job, and resize path in Modules/Database is
 * reused rather than reimplemented.
 *
 * The row's ULID is the data-plane identifier: it is what a client sends as
 * DYNAMODB_CACHE_TABLE. Deliberately opaque rather than the human `name` —
 * the value is machine-injected on attach, so readability buys nothing, and a
 * guessable identifier in an authorization path is a bad trade for cosmetics
 * in a file the customer never edits (decision 14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_caches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index();

            $table->string('name', 80);

            // shared | dedicated
            $table->string('tier', 20)->default('shared')->index();

            // active | provisioning | failed | deleting
            $table->string('status', 20)->default('active')->index();

            // Set only for tier=dedicated. Nullable rather than a separate
            // table because the alternative — a polymorphic backing target —
            // buys nothing at N=2 and costs every query a join it does not need.
            $table->foreignUlid('cloud_database_id')->nullable()->index();

            /**
             * Per-cache byte ceiling. Null falls through to
             * `cache_service.shared.quota_bytes`, so raising the global default
             * lifts every cache that has not been given an explicit one.
             */
            $table->bigInteger('quota_bytes')->nullable();

            /*
             * Usage is deliberately NOT stored here.
             *
             * It lives in `dply_cache_usage` on the `dply_cache` connection,
             * beside the items it counts, because that connection is meant to
             * point at a SEPARATE database — and a trigger on the item store
             * cannot update a table in another database. Putting the counter
             * on this row would work only in the shared configuration and
             * break silently in the isolated one this product recommends.
             *
             * See PostgresCacheStore::usage().
             */

            /**
             * Set on caches adopted by the M4 fold-in of the per-function
             * Valkey clusters, which `CloudResourceCostCalculator` must keep
             * excluding. Those clusters were provisioned when they were free;
             * a refactor is not a reason to start billing someone who did
             * nothing. See docs/adr/dply-cache.md, decision 10.
             */
            $table->timestamp('grandfathered_at')->nullable();

            $table->string('error_message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_caches');
    }
};
