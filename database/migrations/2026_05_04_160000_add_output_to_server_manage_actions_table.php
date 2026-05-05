<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_manage_actions', function (Blueprint $table): void {
            // Persisted SSH stdout/stderr from the action run. The cache
            // (ServerManageRemoteSshJob::cacheKey) keeps the live buffer
            // for ~15 min — this column lets a Services-page log panel
            // surface output well past that TTL. Stored as text; the
            // recorder caps the length so a runaway script can't blow
            // up the row.
            $table->text('output')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('server_manage_actions', function (Blueprint $table): void {
            $table->dropColumn('output');
        });
    }
};
