<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_programs', function (Blueprint $table) {
            $table->json('env_vars')->nullable()->after('is_active');
            $table->string('stdout_logfile', 512)->nullable()->after('env_vars');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('restart_supervisor_programs_after_deploy')->default(false)->after('laravel_scheduler');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('restart_supervisor_programs_after_deploy');
        });

        Schema::table('supervisor_programs', function (Blueprint $table) {
            $table->dropColumn(['env_vars', 'stdout_logfile']);
        });
    }
};
