<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_delivery_logs', function (Blueprint $table) {
            $table->string('provider_event', 64)->nullable()->after('detail');
            $table->string('provider_delivery_id', 128)->nullable()->after('provider_event');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_delivery_logs', function (Blueprint $table) {
            $table->dropColumn(['provider_event', 'provider_delivery_id']);
        });
    }
};
