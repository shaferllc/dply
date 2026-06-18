<?php

declare(strict_types=1);

namespace App\Modules\Docs\Console;

use App\Modules\Docs\Services\DocsSearchIndex;
use Illuminate\Console\Command;

/**
 * Warms (rebuilds) the docs search index cache. Useful on deploy so the first
 * /docs/search-index.json request is hot.
 */
class DocsIndexCommand extends Command
{
    protected $signature = 'docs:index';

    protected $description = 'Rebuild and warm the documentation search index.';

    public function handle(DocsSearchIndex $index): int
    {
        $index->flush();
        $built = $index->cached();

        $this->info('Docs search index built: '.count($built).' entries.');

        return self::SUCCESS;
    }
}
