<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->string('target_engine')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropColumn('target_engine');
        });
    }
};
