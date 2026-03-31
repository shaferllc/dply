<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('user');
            $table->text('description')->nullable()->after('enabled');
            $table->foreignUlid('site_id')->nullable()->after('description')->constrained()->nullOnDelete();
            $table->timestamp('last_run_at')->nullable()->after('last_sync_error');
            $table->longText('last_run_output')->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['enabled', 'description', 'last_run_at', 'last_run_output']);
        });
    }
};
