<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the runtime-agnostic site columns called out in the multi-runtime
 * strategy: `runtime`, `start_command`, `internal_port`.
 *
 * `runtime` is broader than the existing `type` enum (php/node/static) —
 * it adds python/ruby/go and is the canonical pivot for everything in
 * the new detection / provisioner / deploy layers. Backfills from the
 * existing `type` column for the three runtimes that overlap.
 *
 * `start_command` carries the long-running web command for non-PHP/static
 * runtimes (FPM is implicit for PHP, NGINX serves files for static).
 *
 * `internal_port` is the local port the runtime's web server listens on
 * — NGINX proxies via `proxy_pass http://127.0.0.1:{internal_port}`.
 * Per the strategy memory it's allocated from 30000–39999; allocation
 * logic comes in a later commit. Leaving the column nullable for now.
 *
 * Pre-launch: no real users — the strategy memory explicitly says we
 * don't need a back-compat shim. `php_version` stays for now because
 * the existing site-create form still uses it; it'll be dropped in a
 * follow-up commit alongside the form refactor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('runtime', 32)->nullable()->after('type');
            $table->text('start_command')->nullable()->after('build_command');
            $table->unsignedSmallInteger('internal_port')->nullable()->after('app_port');
        });

        // Backfill `runtime` from the existing `type` enum. The three
        // overlapping values (php/node/static) map 1:1; python/ruby/go
        // sites can't exist yet because the form doesn't support them.
        foreach (['php', 'node', 'static'] as $value) {
            DB::table('sites')
                ->where('type', $value)
                ->whereNull('runtime')
                ->update(['runtime' => $value]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['runtime', 'start_command', 'internal_port']);
        });
    }
};
