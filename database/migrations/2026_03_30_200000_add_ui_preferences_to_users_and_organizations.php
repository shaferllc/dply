<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('ui_preferences')->nullable()->after('referral_converted_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('server_site_preferences')->nullable()->after('deploy_email_notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_preferences');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('server_site_preferences');
        });
    }
};
