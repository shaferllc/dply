<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "save to remember" star for inbox notifications. Orthogonal to
 * read_at: a saved item is the user's curated keep-list and is exempt from
 * "delete all read" and retention pruning (saved is sacred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_inbox_items', function (Blueprint $table): void {
            $table->timestamp('saved_at')->nullable()->after('read_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_inbox_items', function (Blueprint $table): void {
            $table->dropColumn('saved_at');
        });
    }
};
