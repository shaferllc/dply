<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deploy_pipelines', function (Blueprint $table) {
            $table->json('deploy_branches')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('site_deploy_pipelines', function (Blueprint $table) {
            $table->dropColumn('deploy_branches');
        });
    }
};
