<?php

declare(strict_types=1);

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Copy existing site_webserver_config_revisions rows into the new
     * generic config_revisions table. The next migration drops the old
     * table once code has been pointed at the new one.
     */
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('site_webserver_config_revisions')) {
            return;
        }
        if (! DB::getSchemaBuilder()->hasTable('config_revisions')) {
            return;
        }

        $subjectType = Site::class;

        DB::table('site_webserver_config_revisions as r')
            ->join('site_webserver_config_profiles as p', 'p.id', '=', 'r.site_webserver_config_profile_id')
            ->join('sites as s', 's.id', '=', 'p.site_id')
            ->select([
                'r.id',
                'r.user_id',
                'r.summary',
                'r.snapshot',
                'r.checksum',
                'r.created_at',
                'r.updated_at',
                's.id as site_id',
                's.server_id as server_id',
            ])
            ->orderBy('r.created_at')
            ->orderBy('r.id')
            ->chunk(500, function ($rows) use ($subjectType): void {
                $insert = [];

                foreach ($rows as $row) {
                    $streamKey = 'site:'.$row->site_id.':webserver_config';

                    $insert[] = [
                        'id' => (string) Str::ulid(),
                        'stream_key' => $streamKey,
                        'server_id' => $row->server_id,
                        'subject_type' => $subjectType,
                        'subject_id' => $row->site_id,
                        'kind' => 'webserver_config',
                        'user_id' => $row->user_id,
                        'summary' => $row->summary,
                        // `snapshot` is already JSON text in the source table.
                        'snapshot' => is_string($row->snapshot) ? $row->snapshot : json_encode($row->snapshot),
                        'checksum' => $row->checksum,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }

                if ($insert !== []) {
                    DB::table('config_revisions')->insert($insert);
                }
            });
    }

    public function down(): void
    {
        // Best-effort reversal: remove only the rows that came from this migration.
        // Identified by kind + subject_type. The original site_webserver_config_revisions
        // table is dropped in a separate migration, so a true round-trip isn't possible.
        DB::table('config_revisions')
            ->where('kind', 'webserver_config')
            ->where('subject_type', Site::class)
            ->delete();
    }
};
