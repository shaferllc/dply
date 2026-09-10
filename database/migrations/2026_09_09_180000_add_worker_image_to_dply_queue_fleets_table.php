<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The image a fleet's workers actually run.
 *
 * Everything else about managed workers shipped in 2026-08-20 — autoscaler,
 * reconciler, Docker runtime, per-second billing rows — but the image was only
 * ever read from `meta['image']`, which nothing outside the tests ever wrote.
 * `FleetReconciler::scaleUp()` therefore logged `queue.fleet.no_image` and
 * started zero workers on every tick, while the panel cheerfully reported
 * "Workers start when jobs arrive". A fleet could be created, resized, paused
 * and billed, and never run anything.
 *
 * Promoted to a real column rather than left in `meta`: it is the one property
 * without which a fleet cannot function, and a required field does not belong
 * in a bag of incidental metadata.
 *
 * The registry password gets the `encrypted` cast every other credential in
 * this codebase uses (`Server::ssh_private_key`,
 * `ServerDatabaseAdminCredential::postgres_password`), so it is never at rest
 * in plaintext. The registry host is not stored — it is derived from the image
 * reference, exactly as `docker login` resolves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dply_queue_fleets', function (Blueprint $table): void {
            $table->string('image', 512)->nullable()->after('status');
            $table->string('registry_username', 255)->nullable()->after('image');
            // text, not string: the ciphertext of a long registry token is
            // several times the length of the token itself.
            $table->text('registry_password')->nullable()->after('registry_username');
        });
    }

    public function down(): void
    {
        Schema::table('dply_queue_fleets', function (Blueprint $table): void {
            $table->dropColumn(['image', 'registry_username', 'registry_password']);
        });
    }
};
