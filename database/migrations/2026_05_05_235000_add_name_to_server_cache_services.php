<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a per-instance `name` to server_cache_services so a single server can run
 * multiple instances of the same engine on different ports (Redis on 6379 for
 * queues + Redis on 6380 for sessions, etc.). Existing single-instance rows get
 * backfilled to `name = 'default'`, which the install scripts treat as the
 * legacy single-instance configuration (legacy systemd unit + legacy config
 * path) so existing servers keep working with no on-box changes.
 *
 * The `(server_id, engine, name)` unique constraint is added in a follow-up
 * migration so the column has values to constrain on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            // Default 'default' so existing rows backfill on creation; the
            // install scripts route name='default' to the legacy paths, keeping
            // the migration zero-touch on already-installed servers.
            $table->string('name', 32)->default('default')->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
