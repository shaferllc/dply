<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_basic_auth_users', function (Blueprint $table): void {
            // Stamped when the operator clicks delete; the row is kept in place so the
            // htpasswd-sync apply can rewrite the file before we hard-delete the record.
            // Hard-delete happens from ApplySiteWebserverConfigJob once the rewrite
            // succeeds, so the UI accurately tracks the server's real state.
            $table->timestamp('pending_removal_at')->nullable()->after('sort_order');
            $table->index(['site_id', 'pending_removal_at']);
        });
    }

    public function down(): void
    {
        Schema::table('site_basic_auth_users', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'pending_removal_at']);
            $table->dropColumn('pending_removal_at');
        });
    }
};
