<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_apps', function (Blueprint $table): void {
            // Connection tier slug (see config('realtime.tiers')) — drives the
            // per-app price and the Worker-side max-connections hard cap. Backfill
            // existing rows to the default tier.
            $table->string('tier')->default('starter')->after('backend');
        });
    }

    public function down(): void
    {
        Schema::table('realtime_apps', function (Blueprint $table): void {
            $table->dropColumn('tier');
        });
    }
};
