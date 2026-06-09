<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_preview_comments', function (Blueprint $table) {
            $table->foreignUlid('parent_id')
                ->nullable()
                ->after('site_id')
                ->constrained('edge_preview_comments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edge_preview_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
