<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_deploy_sync_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('leader_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('site_deploy_sync_group_sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_deploy_sync_group_id')->constrained('site_deploy_sync_groups')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_deploy_sync_group_sites');
        Schema::dropIfExists('site_deploy_sync_groups');
    }
};
