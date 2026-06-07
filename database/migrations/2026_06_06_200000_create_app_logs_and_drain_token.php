<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 of managed logging: storage + routing for the dply Realtime drain.
 *
 *  - `app_logs` holds application log records received from sites that use the
 *    dply Realtime channel. Modelled on `function_invocations` (per-site,
 *    time-indexed, JSON context) so the App logs panel can page it cheaply.
 *  - `sites.log_drain_token` is the per-site routing id. The generated
 *    SyslogUdpHandler stamps it as the syslog `ident`, and the receiver maps an
 *    incoming datagram back to its site by this token. Plaintext + indexed so
 *    the receiver's lookup is a single indexed read; it's a routing id, not a
 *    high-value secret (it only lets a sender attribute logs to this site).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            $table->string('channel', 64)->nullable();
            $table->string('level', 16)->nullable()->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'level', 'created_at']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('log_drain_token', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('log_drain_token');
        });
        Schema::dropIfExists('app_logs');
    }
};
