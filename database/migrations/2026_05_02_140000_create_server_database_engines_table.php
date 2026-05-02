<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tracks which database engines (postgres, mysql, mariadb, etc.) are
 * installed on each server. Distinct from the existing `server_databases`
 * table, which represents user-created database **schemas** (named DBs +
 * credentials) — that concept is unchanged.
 *
 * Per the multi-runtime strategy memo: "multi-engine databases per server,
 * single-engine cache. New table: server_id, engine, version, is_default.
 * Site database_engine defaults to server's default; can be overridden to
 * any engine installed on the server."
 *
 * Backfill: existing servers with a `meta.database` value get a single
 * engine row marked is_default. Servers with no meta.database (cache,
 * load_balancer, static, plain) get nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_database_engines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->string('engine', 32);   // postgres / mysql84 / mariadb / etc.
            $table->string('version', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique(['server_id', 'engine']);
            $table->index(['server_id', 'is_default']);
        });

        // Backfill — every server with `meta.database` set becomes a row.
        // Use raw SQL to read JSON without booting the Server model.
        $servers = DB::table('servers')
            ->select(['id', 'meta', 'created_at'])
            ->get();

        foreach ($servers as $server) {
            $meta = $server->meta;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (! is_array($meta) || empty($meta['database']) || ! is_string($meta['database'])) {
                continue;
            }

            DB::table('server_database_engines')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'server_id' => $server->id,
                'engine' => $meta['database'],
                'version' => null,
                'is_default' => true,
                'created_at' => $server->created_at,
                'updated_at' => $server->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_database_engines');
    }
};
