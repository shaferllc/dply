<?php

use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Folds rows from `site_environment_variables` into the encrypted
 * `sites.env_file_content` blob, which becomes the single source of truth
 * for the new server-canonical env model.
 *
 * Strategy per site:
 *   1. Parse current env_file_content into a key=>value map.
 *   2. Overlay rows whose `environment` matches the site's
 *      `deployment_environment` (rows for other environments are discarded —
 *      the per-environment axis is being dropped).
 *   3. Re-serialize via DotEnvFileWriter (alphabetical, escapeValue rules
 *      identical to SiteDotEnvComposer's previous behavior).
 *   4. Save with env_cache_origin='local-edit' so the next page load shows
 *      the merged blob as a pending edit, not a freshly-synced server file.
 *
 * Decryption is done via Laravel's Crypter directly — we do not depend on
 * the SiteEnvironmentVariable model class because it will be deleted in the
 * same release and Eloquent boot can break under destructive migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parser = app(DotEnvFileParser::class);
        $writer = app(DotEnvFileWriter::class);

        DB::table('sites')->orderBy('id')->chunkById(200, function ($sites) use ($parser, $writer) {
            foreach ($sites as $site) {
                $environment = (string) ($site->deployment_environment ?: 'production');

                $rows = DB::table('site_environment_variables')
                    ->where('site_id', $site->id)
                    ->where('environment', $environment)
                    ->get();

                if ($rows->isEmpty()) {
                    continue;
                }

                $existingBlob = $site->env_file_content !== null
                    ? Crypt::decryptString((string) $site->env_file_content)
                    : '';
                $map = $parser->parse($existingBlob)['variables'];

                foreach ($rows as $row) {
                    $value = $row->env_value !== null
                        ? Crypt::decryptString((string) $row->env_value)
                        : '';
                    $map[(string) $row->env_key] = $value;
                }

                $merged = $writer->render($map);

                DB::table('sites')->where('id', $site->id)->update([
                    'env_file_content' => Crypt::encryptString($merged),
                    'env_cache_origin' => 'local-edit',
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Backfill is one-way. Restoring rows from the merged blob is not
        // attempted — the down() of the table-drop migration recreates the
        // empty schema; no data is restored.
    }
};
