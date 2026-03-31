<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_settings', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('settingsable');
            $table->json('enabled_map')->nullable();
            $table->json('parameters')->nullable();
            $table->timestamps();
        });

        Schema::create('insight_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('insight_key', 80);
            $table->string('dedupe_hash', 64);
            $table->string('status', 24)->default('open');
            $table->string('severity', 24)->default('warning');
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('detected_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status', 'insight_key']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_findings');
        Schema::dropIfExists('insight_settings');
    }
};
