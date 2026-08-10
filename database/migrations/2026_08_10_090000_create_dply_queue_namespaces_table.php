<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dply Queue namespace — the tenancy unit of the managed queue.
 *
 * One row per customer queue endpoint. Modelled on `realtime_apps`: the ULID
 * doubles as the data-plane identifier, so the jobs table keys off it directly
 * and nothing has to translate between a public name and an internal id.
 *
 * Deliberately NOT scoped to a Site. Any Laravel app can hold a namespace,
 * whether or not dply deploys it — that is the whole point of the product.
 * Sites that dply does deploy get one provisioned and wired automatically.
 *
 * Lives in the primary database. Only the job rows go to the `dply_queue`
 * connection (see docs/adr/dply-queue.md, decision 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_namespaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index();

            // Set when dply itself deployed the app this namespace serves, so
            // a push can wake that site's queue pump directly. Null for
            // external apps, which have no pump to wake.
            $table->foreignUlid('site_id')->nullable()->index();

            $table->string('name', 120);
            $table->string('status', 24)->default('active');
            $table->string('tier', 32)->default('standard');

            /**
             * Bumped on rotate / suspend / plan-downgrade. Cached credential
             * tuples carry the epoch they were minted under, so a mismatch
             * forces a re-read. This is how "revoke everything in this
             * namespace" happens without enumerating credentials.
             */
            $table->unsignedInteger('credential_epoch')->default(1);

            // Backstop against a runaway tenant filling the shared jobs table.
            // Enforced as a push rejection, not billed for.
            $table->unsignedInteger('max_queue_depth')->nullable();

            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // The index listing screen: one org's namespaces, newest first.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_namespaces');
    }
};
