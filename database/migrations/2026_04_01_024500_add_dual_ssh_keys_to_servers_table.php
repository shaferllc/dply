<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('ssh_operational_private_key')->nullable()->after('ssh_private_key');
            $table->text('ssh_recovery_private_key')->nullable()->after('ssh_operational_private_key');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn([
                'ssh_operational_private_key',
                'ssh_recovery_private_key',
            ]);
        });
    }
};
