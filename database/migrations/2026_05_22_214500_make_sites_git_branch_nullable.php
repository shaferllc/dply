<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom sites without a repository store null git fields. git_repository_url
 * was already nullable; align git_branch so no-repo creates do not violate
 * the NOT NULL constraint on PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('git_branch')->nullable()->default('main')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('git_branch')->nullable(false)->default('main')->change();
        });
    }
};
