<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide feature-flag overrides.
 *
 * Holds an explicit on/off value for a Pennant flag that beats the
 * config/env default for every scope, but is still beaten by an
 * explicit per-org row in the `features` table. This is what lets a
 * platform admin flip a global default from /admin/flags/all without
 * editing env + config:clear. See App\Support\Admin\PlatformFeatureDefaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_platform_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('enabled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_platform_overrides');
    }
};
