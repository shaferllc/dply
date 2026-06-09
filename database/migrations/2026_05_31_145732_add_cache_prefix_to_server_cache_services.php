<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-instance Laravel-style cache key prefix. Surfaced on the Connection Details
 * card (Overview subtab) and reflected in the Laravel `.env` / Docker Compose
 * snippets as `CACHE_PREFIX=...`. Purely a client-side concern — Redis itself
 * doesn't enforce it; the Laravel cache driver prepends it to every key.
 *
 * Default is null (no prefix); operators set it when a single Redis box backs
 * multiple apps and they want namespace isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            $table->string('cache_prefix', 64)->nullable()->after('auth_password');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            $table->dropColumn('cache_prefix');
        });
    }
};
