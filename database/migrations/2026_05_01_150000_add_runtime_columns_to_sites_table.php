<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('runtime_version', 255)->nullable()->after('php_version');
            $table->text('build_command')->nullable()->after('post_deploy_command');
        });

        DB::table('sites')
            ->whereNotNull('php_version')
            ->update(['runtime_version' => DB::raw('php_version')]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['runtime_version', 'build_command']);
        });
    }
};
