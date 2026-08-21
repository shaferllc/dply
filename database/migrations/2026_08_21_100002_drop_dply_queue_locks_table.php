<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the queue's own lock store.
 *
 * `PostgresQueueLockStore` and the four `/locks/*` routes it backed had no
 * caller and no path to one. They were reachable only by a bespoke bearer-auth
 * HTTP client that does not exist: dply injects exactly one file into a
 * customer app (the Functions handler stub), no `LockProvider`, and stock
 * Laravel's `Cache::lock()` goes to the configured cache store or nowhere.
 *
 * dply Cache supplies locks properly — `Illuminate\Cache\DynamoDbLock` ships in
 * the framework and works over the compatibility endpoint with no injected
 * code, on every runtime. Keeping both would let one logical lock be held
 * twice under two different sets of semantics, which is worse than having no
 * lock store at all.
 *
 * See docs/adr/dply-cache.md, decision 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('dply_queue_locks');
    }

    public function down(): void
    {
        Schema::create('dply_queue_locks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('namespace_id')->index();
            $table->string('name');
            $table->string('owner');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['namespace_id', 'name']);
        });
    }
};
