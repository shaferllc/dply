<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily usage roll-up for dply-managed serverless functions — the FaaS
 * counterpart to edge_usage_snapshots. dply pays the FaaS provider for
 * managed functions, so it meters invocations (and, where a provider exposes
 * it, GiB-seconds) to bill cost-plus on top of the flat management fee.
 *
 * Invocation counts are rolled up from the operational `function_invocations`
 * log by `dply:serverless:collect-usage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serverless_usage_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('invocations')->default(0);
            // GiB-seconds of compute, when the backing provider reports it
            // (Cloudflare/AWS). DigitalOcean Functions does not expose usable
            // per-function metering, so this stays 0 for DO and billing meters
            // invocations instead.
            $table->unsignedBigInteger('gib_seconds')->default(0);
            $table->string('source', 32)->default('placeholder');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'period_start', 'source']);
            $table->index(['organization_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serverless_usage_snapshots');
    }
};
