<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'engine_http_cache_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('engine_http_cache_enabled')->default(false)->after('nginx_extra_raw');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sites', 'engine_http_cache_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('engine_http_cache_enabled');
        });
    }
};
