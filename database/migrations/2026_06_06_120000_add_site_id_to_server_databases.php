<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            // Single-owner link: a database optionally belongs to ONE site.
            // Server-wide databases keep site_id null. No DB-level FK — this
            // schema tracks relationships with plain ulid columns (see
            // server_id) and detaches at the app layer. Detaching never drops
            // the database on the server; that stays an explicit operator action.
            $table->ulid('site_id')->nullable()->after('server_id');
            $table->index('site_id');
        });

        // Backfill: scaffold-created databases stashed their id in the site's
        // meta blob (meta.scaffold.database.id) but never wrote a real link.
        // Adopt those into the new column so they appear under the site's tab
        // immediately. Best-effort + idempotent (only fills null site_id rows).
        $sites = DB::table('sites')
            ->whereNotNull('meta')
            ->select('id', 'meta', 'server_id')
            ->get();

        foreach ($sites as $site) {
            $meta = json_decode((string) $site->meta, true);
            $databaseId = $meta['scaffold']['database']['id'] ?? null;
            if (! is_string($databaseId) || $databaseId === '') {
                continue;
            }

            DB::table('server_databases')
                ->where('id', $databaseId)
                ->where('server_id', $site->server_id)
                ->whereNull('site_id')
                ->update(['site_id' => $site->id]);
        }
    }

    public function down(): void
    {
        Schema::table('server_databases', function (Blueprint $table): void {
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });
    }
};
