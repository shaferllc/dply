<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises a site uptime monitor beyond a plain HTTP GET: `check_type`
 * distinguishes the probe kind (http / ssl) and `config` carries type-specific
 * settings (keyword assertion, expected status, response-time threshold, SSL
 * warn-days). `last_state` persists the richer operational state so a slow-but-up
 * monitor can read DEGRADED (last_ok stays true), and `last_meta` holds
 * type-specific last-check data (e.g. the SSL cert's expiry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_uptime_monitors', function (Blueprint $table): void {
            $table->string('check_type', 32)->default('http')->after('label');
            $table->json('config')->nullable()->after('path');
            $table->string('last_state', 16)->nullable()->after('last_ok');
            $table->json('last_meta')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('site_uptime_monitors', function (Blueprint $table): void {
            $table->dropColumn(['check_type', 'config', 'last_state', 'last_meta']);
        });
    }
};
