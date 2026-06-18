<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // Relative path on the `public` disk of a custom server logo
            // (uploaded). Null = use the generated gradient + initials avatar.
            $table->string('logo_path', 1024)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
