<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking a managed database to the Cloud sites it is attached to.
 *
 * Attaching a database injects its connection env vars into the site's
 * env file; the pivot row records that link so detach can find and
 * remove them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_database_site', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_database_id')->index();
            $table->foreignUlid('site_id')->index();
            $table->timestamps();

            $table->unique(['cloud_database_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_database_site');
    }
};
