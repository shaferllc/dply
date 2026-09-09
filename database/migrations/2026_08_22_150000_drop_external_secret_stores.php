<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Remove external secret stores (2026-08-22).
 *
 * The feature let an organization register its own Vault / AWS Secrets Manager /
 * Doppler store and point a site's env key at a reference inside it, resolved
 * either by dply at deploy or on the box itself. It was only ever reachable from
 * the org Secrets page (to register a store) plus `secrets:residency
 * escalate-external` (to point a key at one) — there was no site-side UI — and
 * it is being withdrawn rather than finished.
 *
 * DESTRUCTIVE. Dropping `external_secret_stores` discards every registered store
 * and its connection config. `store_id` / `.reference` on
 * `site_secret_residencies` are external-mode-only, so rows still in
 * `mode = 'external'` are deleted here — leaving them would only trade one
 * failure (unknown mode) for another. Escrow-mode residencies are untouched.
 *
 * A site that WAS using an external secret keeps a `${dply:secret:…}` placeholder
 * in its loose .env with nothing behind it; its next push fails closed on the
 * unresolvable placeholder until someone sets that key back to a real value.
 * `up()` reports how many rows it removed so that is visible, not silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An external residency has no value in dply to fall back to — its whole
        // point was that the value lived elsewhere — so there is nothing to
        // migrate it to. Drop the pointers with the feature.
        $orphaned = DB::table('site_secret_residencies')->where('mode', 'external')->delete();
        if ($orphaned > 0) {
            $sites = DB::table('site_secret_residencies')->distinct()->count('site_id');
            Log::warning('Dropped '.$orphaned.' external secret residencies with the external-store feature. '
                .'Affected sites keep an unresolvable ${dply:secret:…} placeholder in .env and will fail closed on '
                .'the next push until the key is set to a real value. Remaining residency rows: '.$sites.' sites.');
        }

        Schema::table('site_secret_residencies', function (Blueprint $table): void {
            $table->dropColumn(['store_id', 'reference']);
        });

        Schema::dropIfExists('external_secret_stores');
    }

    public function down(): void
    {
        Schema::create('external_secret_stores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('driver');
            $table->string('name');
            $table->text('config')->nullable();
            $table->string('resolution')->default('dply');
            $table->timestamps();
            $table->index(['organization_id', 'driver']);
        });

        Schema::table('site_secret_residencies', function (Blueprint $table): void {
            $table->char('store_id', 26)->nullable();
            $table->string('reference')->nullable();
        });
    }
};
