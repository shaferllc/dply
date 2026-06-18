<?php

declare(strict_types=1);

namespace App\Modules\Docs\Console;

use App\Modules\Docs\Services\DocsManifest;
use App\Modules\Docs\Services\DocsSearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Clears the cached docs manifest + search index. Run after editing docs or on
 * deploy so the front-matter-driven nav/search picks up changes.
 */
class DocsFlushCommand extends Command
{
    protected $signature = 'docs:flush';

    protected $description = 'Clear the cached documentation manifest and search index.';

    public function handle(): int
    {
        Cache::forget('docs.manifest.v2');
        app(DocsSearchIndex::class)->flush();

        $this->info('Docs manifest + search index cache cleared. Published: '
            .app(DocsManifest::class)->published()->count().' docs.');

        return self::SUCCESS;
    }
}
