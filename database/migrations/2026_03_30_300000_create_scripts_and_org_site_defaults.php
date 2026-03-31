<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->longText('content');
            $table->string('run_as_user', 64)->nullable();
            $table->string('source', 32)->default('user_created'); // user_created | marketplace
            $table->string('marketplace_key', 64)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUlid('default_site_script_id')->nullable()->after('server_site_preferences')->constrained('scripts')->nullOnDelete();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignUlid('deploy_script_id')->nullable()->after('post_deploy_command')->constrained('scripts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['deploy_script_id']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('deploy_script_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['default_site_script_id']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('default_site_script_id');
        });

        Schema::dropIfExists('scripts');
    }
};
