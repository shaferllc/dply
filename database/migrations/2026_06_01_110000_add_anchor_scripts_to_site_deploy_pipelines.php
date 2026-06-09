<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_pipelines', function (Blueprint $table) {
            $table->text('clone_script')->nullable()->after('description');
            $table->text('activate_script')->nullable()->after('clone_script');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_pipelines', function (Blueprint $table) {
            $table->dropColumn(['clone_script', 'activate_script']);
        });
    }
};
