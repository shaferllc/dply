<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per server designated as the dply Logs Vector aggregator (the ingest
 * tier that edges ship to → ClickHouse). Mirrors server_log_agents (the edge side)
 * but holds the generated edge mTLS material so the edge installer can configure
 * shipping without any manual env. See docs/SERVER_LOGS_ADDON.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_log_aggregators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('version')->nullable();
            $table->unsignedInteger('listen_port')->default(6000);

            // The address edges dial (server IP + listen_port). Recorded by the
            // installer so the edge installer reads it instead of config env.
            $table->string('endpoint')->nullable();

            // Generated-on-box edge mTLS material (base64 PEM), captured back by the
            // install job so edges can present a client cert. Encrypted at rest.
            $table->text('edge_ca_cert_b64')->nullable();
            $table->text('edge_client_cert_b64')->nullable();
            $table->text('edge_client_key_b64')->nullable();

            $table->text('install_output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_log_aggregators');
    }
};
