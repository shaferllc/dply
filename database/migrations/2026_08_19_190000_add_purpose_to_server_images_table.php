<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_images', function (Blueprint $table): void {
            $table->string('purpose', 32)->nullable()->after('name');
            $table->index(['organization_id', 'provider', 'purpose', 'status'], 'server_images_bake_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('server_images', function (Blueprint $table): void {
            $table->dropIndex('server_images_bake_lookup_index');
            $table->dropColumn('purpose');
        });
    }
};
