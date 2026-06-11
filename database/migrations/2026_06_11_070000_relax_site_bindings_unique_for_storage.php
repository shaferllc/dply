<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the per-site uniqueness from (site_id, type) to (site_id, type, name)
     * so the `storage` binding can hold several object-storage buckets per site —
     * each its own filesystem disk, keyed by disk-name. Every other binding type
     * persists a fixed `name`, so it still collapses to one row per type.
     *
     * `unique()` is created as a standalone unique index, so swapping it is a
     * plain DROP INDEX / CREATE UNIQUE INDEX — safe on SQLite (no table rebuild).
     */
    public function up(): void
    {
        Schema::table('site_bindings', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'type']);
            $table->unique(['site_id', 'type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('site_bindings', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'type', 'name']);
            $table->unique(['site_id', 'type']);
        });
    }
};
