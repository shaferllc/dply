<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_backup_schedules', function (Blueprint $table) {
            // Default true so operators are alerted by default — opt-out, not opt-in.
            $table->boolean('notify_on_failure')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('server_backup_schedules', function (Blueprint $table) {
            $table->dropColumn('notify_on_failure');
        });
    }
};
