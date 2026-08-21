<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cache a site uses.
 *
 * Mirrors `cloud_database_site` deliberately (docs/adr/dply-cache.md,
 * decision 4): that pivot is the established way an org-owned resource
 * attaches to a site, it is runtime-agnostic, and it already carries the
 * attach/detach env-key discipline this needs.
 *
 * `SiteBinding` was not used. It is VM-only by construction and its `cache`
 * type is a deliberately target-less driver choice; on a VM site the attach
 * action writes a derived binding row so the workspace Resources tab keeps
 * telling the truth, but this table is the source of truth.
 *
 * One cache per site in v1 — hence the unique on `site_id` rather than on the
 * pair. `cloud_database_site.env_prefix` exists so a site can hold two
 * databases; the cache equivalent would need extra stores synthesized into
 * `config/cache.php`, a file the customer owns. The shape allows more later;
 * the constraint enforces one now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache_site', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cache_id')->index();
            $table->foreignUlid('site_id');

            /**
             * The key prefix injected as CACHE_PREFIX. Namespacing already
             * happens server-side (rows are keyed by cache_id), so this exists
             * only so two apps sharing one cache do not collide on `laravel_`
             * defaults — it is a convenience, never a security boundary.
             */
            $table->string('key_prefix', 64)->nullable();

            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_site');
    }
};
