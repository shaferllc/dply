<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queued "quick download" requests. Unlike a backup, this holds no durable
 * artifact — it builds a fresh thing on the server (DB dump, files tar, .env,
 * vhost, logs, home, bundle), uploads it to the operator-managed Hetzner
 * download-staging bucket for 4h, then deletes it on first successful download
 * (or when the sweeper passes expires_at). It never lands on the user's server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_downloads', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->char('organization_id', 26)->nullable()->index();
            $table->char('server_id', 26)->index();
            $table->char('site_id', 26)->nullable()->index();
            // ServerDatabase id for a catalogued DB dump; null for ad-hoc dumps
            // (described by meta.engine + meta.name) and for site artifacts.
            $table->char('server_database_id', 26)->nullable()->index();

            // site | database | adhoc_database — which builder runs.
            $table->string('kind');
            // For site: files|env|vhost|logs|home|bundle. For database/adhoc: dump.
            $table->string('artifact');
            // Ad-hoc dump descriptor: ['engine' => ..., 'name' => ...].
            $table->json('meta')->nullable();

            $table->char('requested_by_user_id', 26)->nullable();

            // pending -> building -> ready -> consumed
            //                     \-> failed
            // (sweeper marks ready rows past expires_at as expired before deletion)
            $table->string('status')->default('pending')->index();

            // Filled once the build lands in the staging bucket.
            $table->string('bucket')->nullable();
            $table->string('object_key', 512)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'kind', 'artifact', 'status']);

            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_downloads');
    }
};
