<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-request reference code (the `X-Dply-Ref` shown on the branded 5xx
 * page) for `http_5xx` error events captured by the Tier-2 sweeper. Indexed so
 * the sweeper can dedupe a reference idempotently and the Errors view can
 * deep-link a row straight into the Tier-1 "resolve a reference" lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('remediation_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
