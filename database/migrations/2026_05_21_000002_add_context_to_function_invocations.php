<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request detail for a function invocation — client IP, country, user
 * agent, matched route, response size, peak memory, etc. Populated for
 * `web` rows by the function handler's ingest report; null for the
 * dply-initiated `tick` / `test` rows. A JSON column so the handler can
 * enrich the payload without a migration per field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            $table->json('context')->nullable()->after('log_lines');
        });
    }

    public function down(): void
    {
        Schema::table('function_invocations', function (Blueprint $table): void {
            $table->dropColumn('context');
        });
    }
};
