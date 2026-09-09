<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the public roadmap (2026-08-22).
 *
 * The `/roadmap` page, its suggestion inbox, the admin kanban, and the
 * post-deploy AI updater are all withdrawn, along with `/changelog`. The
 * creating migrations were deleted with the feature, so this drops the tables
 * for databases that already ran them.
 *
 * DESTRUCTIVE and one-way: every roadmap item, release train, user suggestion,
 * and AI run log is discarded. There is nothing to migrate them to, so `down()`
 * does not recreate the schema — restore from a backup if you need the data.
 *
 * Order matters: roadmap_suggestions references roadmap_items, which references
 * roadmap_releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('roadmap_ai_runs');
        Schema::dropIfExists('roadmap_suggestions');
        Schema::dropIfExists('roadmap_items');
        Schema::dropIfExists('roadmap_releases');
    }

    public function down(): void
    {
        // One-way. See the class docblock.
    }
};
