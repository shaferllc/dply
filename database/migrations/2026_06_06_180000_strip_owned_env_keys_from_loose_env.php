<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Storage and broadcasting bindings now own FILESYSTEM_DISK and
 * BROADCAST_CONNECTION respectively (see ownedEnvKeys). Strip those keys from
 * sites that have the matching binding type so they show as managed rows inside
 * the resource card instead of appearing as loose editable variables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->stripOwnedKey('storage', 'FILESYSTEM_DISK');
        $this->stripOwnedKey('broadcasting', 'BROADCAST_CONNECTION');
    }

    public function down(): void
    {
        // Stripping loose vars is not reversible — the values are preserved in
        // each binding's encrypted injected_env.
    }

    private function stripOwnedKey(string $bindingType, string $envKey): void
    {
        // Find every site that has an active binding of this type.
        $siteIds = DB::table('site_bindings')
            ->where('type', $bindingType)
            ->pluck('site_id');

        if ($siteIds->isEmpty()) {
            return;
        }

        $sites = DB::table('sites')
            ->whereIn('id', $siteIds)
            ->whereNotNull('env_file_content')
            ->get(['id', 'env_file_content']);

        foreach ($sites as $site) {
            $content = (string) $site->env_file_content;
            if ($content === '') {
                continue;
            }

            // Remove the line(s) matching KEY= (handles quoted and unquoted values,
            // and any preceding comment lines that reference only this key).
            $stripped = preg_replace(
                '/^'.preg_quote($envKey, '/').'=.*\n?/m',
                '',
                $content,
            );

            if ($stripped === $content) {
                continue; // Key wasn't there — nothing to do.
            }

            DB::table('sites')
                ->where('id', $site->id)
                ->update(['env_file_content' => $stripped]);
        }
    }
};
