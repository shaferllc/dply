<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Relative path on the `public` disk of a custom site logo
            // (uploaded, or pulled from the live site's favicon). Null = use
            // the generated gradient + initials avatar.
            $table->string('logo_path', 1024)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
