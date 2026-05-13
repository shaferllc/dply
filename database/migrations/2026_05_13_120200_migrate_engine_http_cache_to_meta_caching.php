<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Subsume the boolean `sites.engine_http_cache_enabled` into a richer
 * `meta['caching']` structure on each Site.
 *
 * The boolean column is intentionally LEFT IN PLACE for one release: five
 * builder methods still read it directly (NginxSiteConfigBuilder lines
 * 222/258/303/340, SiteNginxProvisioner line 350). A model observer keeps the
 * column in sync with `meta['caching']['enabled']` while consumers are
 * migrated. A follow-up migration drops the column once production-soak
 * confirms every read path has moved to the meta-backed accessor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')
            ->select(['id', 'meta', 'engine_http_cache_enabled'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = $row->meta;
                    if (is_string($meta)) {
                        $decoded = json_decode($meta, true);
                        $meta = is_array($decoded) ? $decoded : [];
                    }
                    if (! is_array($meta)) {
                        $meta = [];
                    }

                    // Skip if a caching block already exists (rerun-safety).
                    if (isset($meta['caching']) && is_array($meta['caching'])) {
                        continue;
                    }

                    $enabled = (bool) $row->engine_http_cache_enabled;

                    $meta['caching'] = [
                        'enabled' => $enabled,
                        'methods' => $enabled ? ['nginx_http'] : [],
                        'nginx_http' => [
                            'fcgi' => [
                                'ttl_200' => '60m',
                                'ttl_404' => '10m',
                                'min_uses' => 1,
                            ],
                            'proxy' => [
                                'ttl_200' => '60m',
                                'ttl_404' => '10m',
                            ],
                            'bypass_cookies' => [],
                        ],
                        'lscache' => ['enabled' => false, 'rules' => []],
                        'varnish' => ['enabled' => false, 'ttl_default' => '120s'],
                    ];

                    DB::table('sites')
                        ->where('id', $row->id)
                        ->update(['meta' => json_encode($meta)]);
                }
            });
    }

    public function down(): void
    {
        // Best-effort: strip the caching block from meta on rollback. The
        // boolean column was never touched, so wantsEngineHttpCache() keeps
        // working off `engine_http_cache_enabled` unchanged.
        DB::table('sites')
            ->select(['id', 'meta'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = $row->meta;
                    if (is_string($meta)) {
                        $decoded = json_decode($meta, true);
                        $meta = is_array($decoded) ? $decoded : [];
                    }
                    if (! is_array($meta) || ! array_key_exists('caching', $meta)) {
                        continue;
                    }

                    unset($meta['caching']);
                    DB::table('sites')
                        ->where('id', $row->id)
                        ->update(['meta' => json_encode($meta)]);
                }
            });
    }
};
