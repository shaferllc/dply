<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server, per-webserver feature install state for the webserver's NATIVE
 * cache module (nginx HTTP cache zones, Apache mod_cache, Caddy souin build,
 * OpenLiteSpeed LSCache probe). Varnish lives on `server_cache_services` with
 * engine='varnish' — this table is only for the in-webserver caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_webserver_cache_features', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->string('webserver', 32); // nginx | apache | caddy | openlitespeed

            // nginx
            $table->unsignedSmallInteger('nginx_fcgi_zone_size_mb')->default(100);
            $table->unsignedSmallInteger('nginx_proxy_zone_size_mb')->default(100);
            $table->unsignedSmallInteger('nginx_zone_max_size_gb')->default(2);
            $table->unsignedSmallInteger('nginx_zone_inactive_minutes')->default(60);

            // apache (v2 — column scaffolding lives here so the row is created up-front)
            $table->boolean('apache_mod_cache_enabled')->default(false);

            // caddy (v2)
            $table->boolean('caddy_souin_built')->default(false);
            $table->string('caddy_souin_version', 64)->nullable();

            // openlitespeed — read-only probe; LSCache is built into OLS, this just records that we saw it
            $table->boolean('ols_lscache_module_present')->default(false);

            $table->timestamp('last_probed_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique(['server_id', 'webserver']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_webserver_cache_features');
    }
};
