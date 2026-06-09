<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('private_ip_address', 45)->nullable()->after('ip_address');
            // Provider-specific network/VPC identifier stored in meta already for DO
            // (meta.digitalocean.vpc_uuid). For Hetzner we store it here so it can be
            // queried without JSON extraction.
            $table->string('hetzner_network_id')->nullable()->after('private_ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['private_ip_address', 'hetzner_network_id']);
        });
    }
};
