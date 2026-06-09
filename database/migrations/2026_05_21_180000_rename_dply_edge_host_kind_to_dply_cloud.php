<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the persisted host-kind token `dply_edge` -> `dply_cloud`.
 *
 * The Edge product was absorbed into Cloud, so `Server::HOST_KIND_DPLY_CLOUD`
 * now resolves to `'dply_cloud'`. Any existing rows still carrying the old
 * `'dply_edge'` token must be rewritten or they'd stop being recognised as
 * managed-Cloud hosts. The token lives in two places:
 *   - `servers.meta` JSON blob, under the `host_kind` key
 *   - `sites.container_backend` column
 */
return new class extends Migration
{
    public function up(): void
    {
        // servers.meta is a `json` column — cast to jsonb for jsonb_set(),
        // then back to json to match the column type.
        DB::statement(<<<'SQL'
            UPDATE servers
            SET meta = jsonb_set(meta::jsonb, '{host_kind}', '"dply_cloud"')::json
            WHERE meta->>'host_kind' = 'dply_edge'
        SQL);

        DB::table('sites')
            ->where('container_backend', 'dply_edge')
            ->update(['container_backend' => 'dply_cloud']);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE servers
            SET meta = jsonb_set(meta::jsonb, '{host_kind}', '"dply_edge"')::json
            WHERE meta->>'host_kind' = 'dply_cloud'
        SQL);

        DB::table('sites')
            ->where('container_backend', 'dply_cloud')
            ->update(['container_backend' => 'dply_edge']);
    }
};
