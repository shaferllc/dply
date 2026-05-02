<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('site_redirects', 'kind')) {
            return;
        }

        Schema::table('site_redirects', function (Blueprint $table) {
            $table->string('kind', 32)->default('http')->after('site_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('site_redirects', 'kind')) {
            return;
        }

        Schema::table('site_redirects', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
