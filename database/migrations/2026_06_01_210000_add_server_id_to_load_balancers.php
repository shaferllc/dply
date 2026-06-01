<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_balancers', function (Blueprint $table): void {
            // The server running HAProxy for software load balancers.
            // Null for cloud-managed LBs (e.g. Hetzner).
            $table->foreignUlid('server_id')->nullable()->after('provider_credential_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('load_balancers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('server_id');
        });
    }
};
