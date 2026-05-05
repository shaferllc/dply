<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tracks the cache service (redis / valkey / memcached / keydb / dragonfly)
 * installed on each server. Mirrors `server_database_engines` but enforces a
 * single active service per server (Phase 1 invariant) — operators install,
 * uninstall, or swap from the new Caches workspace, but never run two
 * cache engines side-by-side.
 *
 * Backfill: existing servers with a `meta.cache_service` set to anything
 * other than 'none' get a single row in `running` status. The row is the
 * source of truth for the workspace; `meta.cache_service` stays untouched
 * so the legacy provisioner keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_cache_services', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->string('engine', 32);   // redis|valkey|memcached|keydb|dragonfly
            $table->string('version', 64)->nullable();
            $table->string('status', 32)->default('pending'); // pending|installing|running|stopped|failed|uninstalling
            $table->unsignedSmallInteger('port')->default(6379);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique('server_id'); // one cache service per server
        });

        // Backfill servers that already have a cache_service from provisioning.
        $servers = DB::table('servers')
            ->select(['id', 'meta', 'created_at'])
            ->get();

        $defaultPorts = [
            'redis' => 6379,
            'valkey' => 6379,
            'keydb' => 6379,
            'dragonfly' => 6379,
            'memcached' => 11211,
        ];

        foreach ($servers as $server) {
            $meta = $server->meta;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            $engine = is_array($meta) ? ($meta['cache_service'] ?? null) : null;
            if (! is_string($engine) || $engine === '' || $engine === 'none') {
                continue;
            }

            DB::table('server_cache_services')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'server_id' => $server->id,
                'engine' => $engine,
                'version' => null,
                'status' => 'running',
                'port' => $defaultPorts[$engine] ?? 6379,
                'error_message' => null,
                'created_at' => $server->created_at,
                'updated_at' => $server->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_cache_services');
    }
};
