<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_items', function (Blueprint $table): void {
            $table->string('target_quarter', 16)->nullable()->after('area');
        });

        Schema::table('roadmap_suggestions', function (Blueprint $table): void {
            $table->foreignUlid('promoted_roadmap_item_id')
                ->nullable()
                ->after('status')
                ->constrained('roadmap_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_suggestions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promoted_roadmap_item_id');
        });

        Schema::table('roadmap_items', function (Blueprint $table): void {
            $table->dropColumn('target_quarter');
        });
    }
};
