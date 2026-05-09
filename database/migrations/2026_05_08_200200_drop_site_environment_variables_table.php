<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the per-row site_environment_variables table now that
 * `sites.env_file_content` is the single store for site-scoped env.
 * The previous migration backfilled merged values into env_file_content;
 * this one removes the now-unused table.
 *
 * down() recreates the empty schema for rollbacks; data is not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('site_environment_variables');
    }

    public function down(): void
    {
        Schema::create('site_environment_variables', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('site_id', 26);
            $table->string('env_key', 128);
            $table->text('env_value')->nullable();
            $table->string('environment', 32)->default('production');
            $table->timestamps();

            $table->unique(['site_id', 'env_key', 'environment']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }
};
