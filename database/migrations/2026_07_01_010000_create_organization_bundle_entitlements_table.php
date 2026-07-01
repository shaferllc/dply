<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The persisted, per-org state of the bundled-products perk (free tracely +
 * Lookout). One row per org that has EVER qualified — its presence + status is
 * the baseline the synchronizer diffs against `Organization::qualifiesForBundledProducts()`
 * to decide which `bundle.*` transition to emit. An org that never qualified has
 * no row. See docs/adr/bundled-products-sso.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_bundle_entitlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();

            // active | suspended | deleted — the last transition applied. 'deleted'
            // means the retention window elapsed and downstream workspaces were
            // purged; the row is kept as a tombstone so re-qualifying re-provisions
            // cleanly rather than resuming purged data.
            $table->string('status')->index();

            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_bundle_entitlements');
    }
};
