<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes grow from a flat list into a small notebook: free-form tags for
 * filtering and an archive so stale runbooks stop crowding the active list
 * without losing the history (no soft deletes anywhere in this app — archiving
 * is an explicit, visible state, mirroring EdgePreviewComment.resolved_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_notes', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('pinned');
            $table->timestamp('archived_at')->nullable()->after('tags');
            $table->foreignUlid('archived_by_user_id')->nullable()->after('archived_at')
                ->constrained('users')->nullOnDelete();

            // The notes list is always filtered by archive state first.
            $table->index(['server_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('server_notes', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'archived_at']);
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn(['tags', 'archived_at']);
        });
    }
};
