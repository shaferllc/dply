<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_database_engines', function (Blueprint $table): void {
            $table->boolean('remote_access')->default(false)->after('port');
            $table->string('allowed_from', 500)->nullable()->after('remote_access');
        });
    }

    public function down(): void
    {
        Schema::table('server_database_engines', function (Blueprint $table): void {
            $table->dropColumn(['remote_access', 'allowed_from']);
        });
    }
};
