<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General-settings fields for the new Organization → General tab: a branding
 * icon/logo (public-disk path, mirrors sites.logo_path), a free-text
 * description, and a default timezone. name/slug/email already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('icon_path')->nullable()->after('email');
            $table->text('description')->nullable()->after('icon_path');
            $table->string('timezone')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['icon_path', 'description', 'timezone']);
        });
    }
};
