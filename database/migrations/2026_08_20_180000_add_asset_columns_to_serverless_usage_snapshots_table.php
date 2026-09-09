<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meters published front-end assets alongside function compute.
 *
 * A managed function's Vite build lives in dply's object storage and is
 * delivered from dply's CDN, so it costs real money that invocations and
 * GiB-seconds do not capture.
 *
 * Two dimensions, because DigitalOcean Spaces bills exactly two things:
 * stored GiB and outbound GiB. There is deliberately no operations meter —
 * Spaces charges no per-request fee, so the Class A / Class B columns that
 * edge_usage_snapshots carries for Cloudflare R2 have no analogue here.
 *
 * `asset_requests` is recorded but priced at zero: it costs nothing to serve,
 * and having the number from day one means abuse is visible and can be priced
 * later without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serverless_usage_snapshots', function (Blueprint $table): void {
            // Measured exactly, by listing the site's bucket prefix — not
            // estimated from build metadata.
            $table->unsignedBigInteger('asset_storage_bytes')->default(0)->after('gib_seconds');
            // Edge bytes served to browsers, per hostname, from Cloudflare
            // analytics. Deduplicated before it lands here: see
            // ServerlessAssetEgressReader for why a custom asset domain would
            // otherwise be counted twice.
            $table->unsignedBigInteger('asset_bytes_egress')->default(0)->after('asset_storage_bytes');
            $table->unsignedBigInteger('asset_requests')->default(0)->after('asset_bytes_egress');
        });
    }

    public function down(): void
    {
        Schema::table('serverless_usage_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['asset_storage_bytes', 'asset_bytes_egress', 'asset_requests']);
        });
    }
};
