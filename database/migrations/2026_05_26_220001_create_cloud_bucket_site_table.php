<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking buckets to Cloud sites. env_prefix mirrors the
 * cloud_database_site pattern — each attachment writes its own
 * ${PREFIX}_BUCKET / ${PREFIX}_ENDPOINT / ${PREFIX}_ACCESS_KEY_ID set so
 * two buckets on the same app don't collide on env vars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_bucket_site', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_bucket_id')->index();
            $table->foreignUlid('site_id')->index();
            $table->string('env_prefix', 40)->default('S3');
            $table->timestamps();

            $table->unique(['cloud_bucket_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_bucket_site');
    }
};
