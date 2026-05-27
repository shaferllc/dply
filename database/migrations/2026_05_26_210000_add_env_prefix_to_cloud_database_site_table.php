<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attachment env-var prefix. Multiple databases can attach to the
 * same site only if their prefixes differ — that's what keeps
 * `${PREFIX}_HOST`, `${PREFIX}_PORT`, etc. from colliding when the app
 * has more than one Postgres or more than one Redis. Default 'DB'
 * matches the existing single-database behavior so old rows are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_database_site', function (Blueprint $table): void {
            $table->string('env_prefix', 40)->default('DB')->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('cloud_database_site', function (Blueprint $table): void {
            $table->dropColumn('env_prefix');
        });
    }
};
