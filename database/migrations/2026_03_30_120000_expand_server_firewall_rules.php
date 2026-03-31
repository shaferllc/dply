<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->string('name', 160)->nullable()->after('server_id');
            $table->string('source', 128)->default('any')->after('protocol');
            $table->boolean('enabled')->default(true)->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->dropColumn(['name', 'source', 'enabled']);
        });
    }
};
