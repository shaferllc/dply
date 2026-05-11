<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies any existing `notes` content into the new unified `comment` field
 * on `site_tenant_domains`. The next migration drops `notes`, so this is
 * the one chance to preserve operator-typed prose. Skips rows that already
 * have a comment (just-added rows during a rolling deploy) so we don't
 * clobber anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_tenant_domains')
            ->whereNotNull('notes')
            ->whereNull('comment')
            ->update(['comment' => DB::raw('notes')]);
    }

    public function down(): void
    {
        // One-way migration. The drop-notes migration's down() recreates
        // the column without restoring data; that's good enough for a
        // rollback drill since the merged content lives in `comment` now.
    }
};
