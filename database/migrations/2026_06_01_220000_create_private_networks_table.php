<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_id')->nullable();          // Hetzner network ID / DO VPC UUID
            $table->string('name');
            $table->string('provider', 32)->default('hetzner'); // hetzner | digitalocean
            $table->string('ip_range', 64)->nullable();
            $table->string('network_zone', 64)->nullable();     // Hetzner: eu-central etc.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->foreignUlid('private_network_id')
                ->nullable()
                ->after('hetzner_network_id')
                ->constrained('private_networks')
                ->nullOnDelete();
        });

        // Backfill: convert existing hetzner_network_id strings into private_networks rows
        // and point servers at them.
        $servers = DB::table('servers')
            ->whereNotNull('hetzner_network_id')
            ->where('hetzner_network_id', '!=', '')
            ->where('provider', 'hetzner')
            ->get(['id', 'organization_id', 'provider_credential_id', 'hetzner_network_id', 'private_ip_address']);

        $networksByOrgAndId = [];

        foreach ($servers as $server) {
            $key = $server->organization_id.':'.$server->hetzner_network_id;

            if (! isset($networksByOrgAndId[$key])) {
                $networkId = Str::ulid()->toString();
                DB::table('private_networks')->insert([
                    'id' => $networkId,
                    'organization_id' => $server->organization_id,
                    'provider_credential_id' => $server->provider_credential_id,
                    'provider_id' => $server->hetzner_network_id,
                    'name' => 'hetzner-network-'.$server->hetzner_network_id,
                    'provider' => 'hetzner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $networksByOrgAndId[$key] = $networkId;
            }

            DB::table('servers')
                ->where('id', $server->id)
                ->update(['private_network_id' => $networksByOrgAndId[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('private_network_id');
        });

        Schema::dropIfExists('private_networks');
    }
};
