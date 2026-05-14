<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sites belonging to a ploi_servers row. Same removed_from_source semantics as
 * the parent table; site_type drives the v1 eligibility gate (laravel/php are
 * eligible, everything else is unsupported_v1 — see PloiImportDriver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ploi_sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ploi_server_id')->constrained('ploi_servers')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->string('domain', 255);
            $table->string('site_type', 32);
            $table->string('php_version', 16)->nullable();
            $table->string('repository_url', 500)->nullable();
            $table->string('repository_branch', 255)->nullable();
            $table->string('web_directory', 500)->nullable();
            $table->string('status', 64)->nullable();
            $table->boolean('removed_from_source')->default(false);
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['ploi_server_id', 'source_id'], 'ploi_sites_server_source_unq');
            $table->index(['ploi_server_id', 'removed_from_source'], 'ploi_sites_server_removed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ploi_sites');
    }
};
