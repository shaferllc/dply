<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('scheduled_deletion_at')->nullable()->after('updated_at');
            $table->index('scheduled_deletion_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_deletion_at']);
            $table->dropColumn('scheduled_deletion_at');
        });
    }
};
