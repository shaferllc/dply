<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_webserver_config_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('webserver', 32);
            $table->string('mode', 24)->default('layered'); // layered | full_override
            $table->longText('before_body')->nullable();
            $table->longText('main_snippet_body')->nullable();
            $table->longText('after_body')->nullable();
            $table->longText('full_override_body')->nullable();
            $table->string('last_applied_effective_checksum', 64)->nullable();
            $table->string('last_applied_core_hash', 64)->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_webserver_config_profiles');
    }
};
