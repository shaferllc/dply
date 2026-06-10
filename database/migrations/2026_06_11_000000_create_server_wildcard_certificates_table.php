<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-(server, zone) wildcard TLS certificates for dply-managed testing zones
 * (e.g. *.on-dply.com). A single wildcard installed at
 * /etc/letsencrypt/live/<zone>/ secures every testing hostname on that server,
 * so a new site's vhost can emit its :443 block the instant it is written — no
 * per-site Let's Encrypt issuance, no HTTP-01 DNS race, and ~1 cert per
 * server/zone per renewal instead of one per site (which would blow the LE
 * 50-certs-per-registered-domain-per-week limit on the shared on-dply.* apex).
 *
 * Issued via certbot --manual DNS-01 with per-provider hook scripts
 * (DigitalOcean / Hetzner / Cloudflare). See WildcardCertificateIssuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_wildcard_certificates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->index();
            // The registered testing zone the wildcard covers, e.g. on-dply.com.
            $table->string('zone');
            // DNS-01 provider that controls the zone: digitalocean | hetzner | cloudflare.
            $table->string('provider', 32);
            // Operator credential controlling the zone (null = app-level DO token path).
            $table->foreignUlid('provider_credential_id')->nullable();
            // pending | issuing | active | failed | expired | removed.
            $table->string('status', 16)->default('pending');
            // /etc/letsencrypt/live/<live_directory>/ basename (normally == zone).
            $table->string('live_directory')->nullable();
            $table->string('cert_path')->nullable();
            $table->string('key_path')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->timestamp('last_requested_at')->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamp('last_installed_at')->nullable();
            $table->text('last_output')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // One wildcard per server/zone — the idempotency + concurrency anchor.
            $table->unique(['server_id', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_wildcard_certificates');
    }
};
