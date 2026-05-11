<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic revision store for files/configs Dply manages on remote
     * hosts. One row per captured snapshot. Callers compute their own
     * stream_key (opaque to this table) so streams can be PHP ini files,
     * webserver-config profile bundles, or any future managed artifact.
     *
     * snapshot shape varies by kind:
     *   - single-file kinds (php_cli_ini, php_fpm_ini, php_pool, ...):
     *       {"path": "/etc/php/8.4/cli/php.ini", "content": "..."}
     *   - webserver_config (layered bundle):
     *       {"mode": "layered", "before_body": "...", "main_snippet_body": "...",
     *        "after_body": "...", "full_override_body": null}
     */
    public function up(): void
    {
        Schema::create('config_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Opaque, caller-defined identifier for "the thing being revisioned".
            // Examples:
            //   server:01HX.../php/8.4/cli_ini
            //   site:01HY.../webserver_config
            $table->string('stream_key');

            // Denormalized owner pointers. server_id is set when the artifact lives
            // on a specific host (almost always); subject_* points at the logical
            // owner (Site for webserver_config; null for server-level files).
            $table->foreignUlid('server_id')->nullable()->constrained('servers')->cascadeOnDelete();
            $table->nullableUlidMorphs('subject', 'config_revisions_subject_idx');

            // Discriminator for snapshot shape and handler routing.
            // e.g. 'php_cli_ini', 'php_fpm_ini', 'php_pool', 'webserver_config'.
            $table->string('kind', 64);

            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary')->nullable();

            $table->json('snapshot');

            // sha256 over a canonical encoding of `snapshot`. Used for dedup
            // (skip recording when the latest revision in the stream already
            // has this checksum) and for drift detection (compare against
            // the live file's checksum).
            $table->char('checksum', 64);

            $table->timestamps();

            $table->index(['stream_key', 'created_at'], 'config_revisions_stream_idx');
            $table->index(['server_id', 'kind', 'created_at'], 'config_revisions_server_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_revisions');
    }
};
