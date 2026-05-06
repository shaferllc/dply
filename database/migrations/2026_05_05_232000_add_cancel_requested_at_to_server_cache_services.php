<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            // Set by the operator's "Cancel" button while a row is pending/installing. The
            // InstallCacheServiceJob polls this on each stdout flush; when it flips, the job
            // aborts the SSH stream, runs apt purge as a best-effort revert, then deletes the row.
            $table->timestamp('cancel_requested_at')->nullable()->after('install_output');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
