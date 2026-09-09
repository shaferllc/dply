<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themes and plugins that come from their own Git repository.
 *
 * A site has exactly one `git_repository_url` on the sites table, which is the
 * application itself. WordPress is the case where that is not enough: a site's
 * theme and each of its plugins are independently versioned units, each with
 * its own repo, branch and deploy key. Hence one row per unit here rather than
 * more columns on sites.
 *
 * Layout-agnostic on purpose. The same row means "this theme comes from this
 * repo" whether the site is classic WordPress (cloned into
 * wp-content/themes/<slug>) or Bedrock (a Composer vcs repository + require).
 * See App\Modules\WordPress\Materializers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_git_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();

            // theme | plugin
            $table->string('kind');

            // Directory name under wp-content/themes|plugins (classic) and the
            // package name suffix (Bedrock). WordPress resolves themes and
            // plugins by this, so it is the identity, not a label.
            $table->string('slug');

            $table->string('repository_url');
            $table->string('git_branch')->default('main');

            // Composer package name for the Bedrock materializer
            // (e.g. "acme/my-theme"). Null on classic sites, which address the
            // repo by path instead.
            $table->string('composer_package')->nullable();

            // Per-source deploy keypair: a private theme repo needs read access
            // that is independent of the site's own repo, so revoking one never
            // breaks the others.
            $table->text('deploy_key_private')->nullable();
            $table->text('deploy_key_public')->nullable();

            // pending | syncing | synced | error
            $table->string('status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_synced_commit')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // WordPress itself cannot hold two themes or two plugins in the
            // same directory, so the (site, kind, slug) triple is the real
            // uniqueness constraint — not the repo URL, since one repo may
            // legitimately be added twice under different slugs.
            $table->unique(['site_id', 'kind', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_git_sources');
    }
};
