<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_processes', function (Blueprint $table): void {
            if (! Schema::hasColumn('site_processes', 'meta')) {
                $table->jsonb('meta')->nullable()->after('managed_by_manifest');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_processes', function (Blueprint $table): void {
            if (Schema::hasColumn('site_processes', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }
};
