<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('site_redirects', 'response_headers')) {
            return;
        }

        Schema::table('site_redirects', function (Blueprint $table) {
            $table->json('response_headers')->nullable()->after('status_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('site_redirects', 'response_headers')) {
            return;
        }

        Schema::table('site_redirects', function (Blueprint $table) {
            $table->dropColumn('response_headers');
        });
    }
};
