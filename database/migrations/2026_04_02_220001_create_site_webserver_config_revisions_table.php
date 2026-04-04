<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_webserver_config_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_webserver_config_profile_id')->constrained('site_webserver_config_profiles')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary')->nullable();
            $table->json('snapshot');
            $table->string('checksum', 64);
            $table->timestamps();

            $table->index(['site_webserver_config_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_webserver_config_revisions');
    }
};
